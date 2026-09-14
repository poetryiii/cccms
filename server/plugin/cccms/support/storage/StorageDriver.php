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

    abstract public function delete(string $path): void;

    abstract public function url(string $path): string;

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
