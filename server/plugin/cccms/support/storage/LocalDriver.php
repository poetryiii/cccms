<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\SysConfig;
use Webman\Http\UploadFile;

/** 本地驱动：落盘 public/storage，同域访问。 */
final class LocalDriver extends StorageDriver
{
    public function upload(UploadFile $file): array
    {
        [$ext, $size] = $this->validate($file);

        $path = $this->objectName($ext);
        $full = $this->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        // move() 会自动创建目录
        $file->move($full);

        return $this->result($file, $path, $ext, $size, sha1_file($full) ?: '');
    }

    public function delete(string $path): void
    {
        // realpath 校验，防目录穿越
        $base = realpath($this->root());
        $real = realpath($this->root() . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
        if ($base && $real && str_starts_with($real, $base) && is_file($real)) {
            @unlink($real);
        }
    }

    public function url(string $path): string
    {
        // URL 前缀取后台配置（upload.url_prefix），切独立域名 / CDN 时改配置即可
        $prefix = SysConfig::getString('upload.url_prefix')
            ?: (string)config('plugin.cccms.filesystem.url_prefix', '/storage');

        return rtrim($prefix, '/') . '/' . ltrim($path, '/');
    }

    private function root(): string
    {
        return public_path() . DIRECTORY_SEPARATOR . 'storage';
    }
}
