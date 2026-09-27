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
     * 上传一个完整文件。
     *
     * `$maxBytes` 是**本次允许的最大字节数**，`null` 表示用配置里的 `upload.max_size`。
     * 之所以把它做成显式参数而不是可变的成员 / 静态属性：驱动实例由
     * `StorageManager` **静态缓存**（多请求共用一个对象），任何可变状态都会被
     * 并发请求互相污染 —— 分片上传合并出的文件上限远高于单请求上传，
     * 一旦串味就会出现「这次放行了 2GB、下个请求也跟着放行」。
     *
     * @return array{path:string,name:string,original_name:string,url:string,size:int,mime:string,ext:string,hash:string}
     */
    abstract public function upload(UploadFile $file, ?int $maxBytes = null): array;

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
     * 白名单优先取后台配置（upload.ext_allow），未配置时回退到 filesystem.php 的默认值；
     * 大小上限取 `upload.max_size`，也可由调用方用 `$maxBytes` 显式覆盖
     * （分片上传合并后的文件走这条）；`DANGEROUS_EXT` 为硬拒绝，不受配置影响。
     *
     * 一并返回**服务端探测到的 MIME**：本地驱动紧接着就会把临时文件 `move()` 走，
     * 之后再探测只能拿到失败值，所以探测必须发生在校验阶段。
     *
     * @param  int|null $maxBytes 本次允许的最大字节数；null = 用 `upload.max_size`
     * @return array{0:string,1:int,2:string} [扩展名, 字节数, 服务端 MIME]
     */
    final protected function validate(UploadFile $file, ?int $maxBytes = null): array
    {
        $ext = strtolower((string)$file->getUploadExtension());
        self::assertExtAllowed($ext);

        $size = (int)$file->getSize();
        if ($size <= 0) {
            throw new ApiException('文件为空', 422);
        }

        $maxMb = SysConfig::getInt('upload.max_size', 0);
        $max   = $maxBytes ?? ($maxMb > 0 ? $maxMb * 1048576 : (int)config('plugin.cccms.filesystem.max_size', 10 * 1024 * 1024));
        if ($size > $max) {
            throw new ApiException('文件超出大小限制（最大 ' . round($max / 1048576, 1) . 'MB）', 422);
        }

        $this->assertContentMatches($file, $ext);

        return [$ext, $size, self::probeMime($file)];
    }

    /**
     * 服务端 MIME 探测（finfo，作用于**临时文件**）。
     *
     * 客户端上报的 `getUploadMimeType()` 可被任意伪造、且随浏览器 / 系统而变，
     * 因此**入库与展示**统一用服务端探测结果。探测不到时（finfo 不可用，或
     * 类型无魔数被识别为 `application/octet-stream`）回退客户端上报值，
     * 避免整列 MIME 变成 octet-stream。
     *
     * 公开为静态方法：分片上传（P2-9）合并出的临时文件同样需要按服务端探测值入库，
     * 单测也需要能直接验证「客户端上报值不会进库」。
     */
    public static function probeMime(UploadFile $file): string
    {
        $mime = self::finfoMime($file);
        if ($mime !== null && $mime !== 'application/octet-stream') {
            return $mime;
        }

        return (string)$file->getUploadMimeType();
    }

    /**
     * 原始 finfo 探测值（不做任何回退）；无法探测时返回 null。
     *
     * 魔数校验必须用这个**未经回退**的值 —— 一旦回退到客户端上报值，
     * 校验就形同虚设。
     */
    private static function finfoMime(UploadFile $file): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        $path = (string)$file->getPathname();
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === '' ? null : $mime;
    }

    /**
     * 仅校验扩展名白名单（不涉及文件本体）。
     *
     * 抽出来是给**分片上传的 init 阶段**用的：类型不合法要在只传了几百字节时就拒绝，
     * 而不是等用户传完 100MB 再报错。`validate()` 也调用它，保证只有一份判定逻辑。
     *
     * 硬拒绝优先于白名单：配置里残留 `svg` 也不能放行（见 `DANGEROUS_EXT` 说明）。
     */
    public static function assertExtAllowed(string $ext): void
    {
        $ext = strtolower($ext);
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
        if ($rules === []) {
            return;
        }

        $mime = self::finfoMime($file);
        if ($mime === null || in_array($mime, $rules, true)) {
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
    final protected function result(UploadFile $file, string $path, string $ext, int $size, string $hash, string $mime): array
    {
        return [
            'path'          => $path,
            'name'          => basename($path),
            'original_name' => (string)$file->getUploadName(),
            'url'           => $this->url($path),
            'size'          => $size,
            'mime'          => $mime,
            'ext'           => $ext,
            'hash'          => $hash,
        ];
    }
}
