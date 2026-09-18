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
     * 扩展名白名单 + 大小上限校验。
     *
     * 白名单与上限优先取后台配置（upload.ext_allow / upload.max_size），
     * 未配置时回退到 filesystem.php 的默认值。
     *
     * @return array{0:string,1:int} [扩展名, 字节数]
     */
    final protected function validate(UploadFile $file): array
    {
        $ext = strtolower((string)$file->getUploadExtension());

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

        return [$ext, $size];
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
