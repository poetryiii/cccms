<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SysConfig;
use Webman\Http\UploadFile;

/** 存储驱动基类：统一校验与结果结构。 */
abstract class StorageDriver
{
    /**
     * 一律拒绝的扩展名（**硬编码，不受后台白名单影响**）。
     *
     * 本地驱动落盘 `public/storage` 且与后台同域直出，浏览器会按响应头的 Content-Type
     * 内联渲染。这些类型可携带并执行脚本（SVG 内嵌 `<script>`、HTML 直接执行），
     * 一旦被访问就形成存储型 XSS，可读取 `localStorage` 中的 token 接管后台。
     * 因此即便管理员把它们配进白名单，也必须拒绝。
     */
    public const DANGEROUS_EXT = ['svg', 'svgz', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'xsl', 'htc', 'mhtml'];

    /**
     * 内容（魔数）校验规则：扩展名 => 允许的 MIME 列表。
     *
     * 只收录**能被 libmagic 稳定识别**的类型。`txt` / `csv` / `md` 无魔数，
     * `doc` / `xls` / `ppt` 等旧二进制格式识别结果因平台而异，都不在此列——
     * 对它们做校验只会误伤正常文件，而它们本身不是脚本执行载体。
     */
    private const MAGIC_RULES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'pdf'  => ['application/pdf'],
        'zip'  => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/gzip'],
        'docx' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        '7z'   => ['application/x-7z-compressed'],
        'rar'  => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
    ];

    /**
     * @return array{path:string,name:string,original_name:string,url:string,size:int,mime:string,ext:string,hash:string}
     */
    abstract public function upload(UploadFile $file): array;

    /**
     * 把一段内容写入**指定对象路径**（附件上传之外的第二条入口）。
     *
     * upload() 会自动生成随机对象名，适合附件；日志归档需要稳定、可追溯的路径
     * （log-archive/2026/09/log-20260918-040000-1.jsonl.gz），所以单独开这个口子。
     * 不做扩展名白名单校验（内容由调用方负责），路径统一过 objectPath() 防穿越。
     *
     * @return array{path:string,size:int,hash:string}
     */
    abstract public function put(string $content, string $path): array;

    abstract public function delete(string $path): void;

    abstract public function url(string $path): string;

    /**
     * 规范化并校验调用方给出的对象路径：拒绝空值 / 目录穿越。
     *
     * 与 upload() 的 objectName() 不同，这里的路径来自调用方，必须显式设防。
     */
    final protected function objectPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new ApiException('非法的存储路径：' . $path, 422);
        }

        return $path;
    }

    public function name(): string
    {
        return static::class;
    }

    /**
     * 扩展名白名单 + 内容魔数 + 大小上限校验。
     *
     * 白名单与上限优先取后台配置（upload.ext_allow / upload.max_size），
     * 未配置时回退到 filesystem.php 的默认值；`DANGEROUS_EXT` 为硬拒绝，
     * 不受配置影响。
     *
     * @return array{0:string,1:int} [扩展名, 字节数]
     */
    final protected function validate(UploadFile $file): array
    {
        $ext = strtolower((string)$file->getUploadExtension());

        // 硬拒绝优先于白名单：配置里残留 svg 也不能放行（见 DANGEROUS_EXT 说明）
        if (in_array($ext, self::DANGEROUS_EXT, true)) {
            throw new ApiException('不支持的文件类型：' . $ext, 422);
        }

        $allow = SysConfig::getList('upload.ext_allow');
        if ($allow === []) {
            $allow = array_map('strtolower', (array)config('plugin.cccms.filesystem.allow_ext', []));
        }
        if ($ext === '' || !in_array($ext, $allow, true)) {
            throw new ApiException('不支持的文件类型：' . ($ext ?: '未知'), 422);
        }

        $size = (int)$file->getSize();
        if ($size <= 0) {
            throw new ApiException('文件为空', 422);
        }

        $maxMb = SysConfig::getInt('upload.max_size', 0);
        $max   = $maxMb > 0 ? $maxMb * 1048576 : (int)config('plugin.cccms.filesystem.max_size', 10 * 1024 * 1024);
        if ($size > $max) {
            throw new ApiException('文件超出大小限制（最大 ' . round($max / 1048576, 1) . 'MB）', 422);
        }

        $this->assertContentMatches($file, $ext);

        return [$ext, $size];
    }

    /**
     * 魔数校验：把 `.png` 改名的脚本挡在门外。
     *
     * 客户端上报的 MIME（`UploadFile::getUploadMimeType()`）完全不可信，
     * 因此只认服务端对**临时文件**的探测结果。未收录的类型跳过校验。
     */
    private function assertContentMatches(UploadFile $file, string $ext): void
    {
        $rules = self::MAGIC_RULES[$ext] ?? [];
        if ($rules === [] || !class_exists(\finfo::class)) {
            return;
        }

        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file->getPathname());
        if ($mime === '' || in_array($mime, $rules, true)) {
            return;
        }

        throw new ApiException('文件内容与扩展名不符（检测到 ' . $mime . '）', 422);
    }

    /** 对象名：20260912/103000ab12cd34ef.png（随机化，不使用原始文件名） */
    final protected function objectName(string $ext): string
    {
        return date('Ymd') . '/' . date('His') . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    /**
     * @return array{path:string,name:string,original_name:string,url:string,size:int,mime:string,ext:string,hash:string}
     */
    final protected function result(UploadFile $file, string $path, string $ext, int $size, string $hash): array
    {
        return [
            'path'          => $path,
            'name'          => basename($path),
            'original_name' => (string)$file->getUploadName(),
            'url'           => $this->url($path),
            'size'          => $size,
            'mime'          => (string)$file->getUploadMimeType(),
            'ext'           => $ext,
            'hash'          => $hash,
        ];
    }
}
