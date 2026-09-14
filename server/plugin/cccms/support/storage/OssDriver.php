<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\ApiException;
use Webman\Http\UploadFile;

/**
 * 阿里云 OSS 驱动。
 *
 * 依赖（按需安装）：composer require aliyuncs/oss-sdk-php
 */
final class OssDriver extends StorageDriver
{
    public function upload(UploadFile $file): array
    {
        [$ext, $size] = $this->validate($file);

        $path = $this->objectName($ext);
        $local = (string)$file->getPathname();
        $hash = sha1_file($local) ?: '';

        $this->client()->putObject($this->cfg('bucket'), $path, $local);

        return $this->result($file, $path, $ext, $size, $hash);
    }

    public function delete(string $path): void
    {
        $this->client()->deleteObject($this->cfg('bucket'), $path);
    }

    public function url(string $path): string
    {
        $domain = rtrim($this->cfg('domain'), '/');
        if ($domain !== '') {
            return $domain . '/' . ltrim($path, '/');
        }
        return rtrim($this->cfg('endpoint'), '/') . '/' . $this->cfg('bucket') . '/' . ltrim($path, '/');
    }

    private function client(): \OSS\OssClient
    {
        if (!class_exists(\OSS\OssClient::class)) {
            throw new ApiException('未安装阿里云 OSS SDK：composer require aliyuncs/oss-sdk-php', 500);
        }
        $id = $this->cfg('access_key_id');
        $secret = $this->cfg('access_key_secret');
        if ($id === '' || $secret === '' || $this->cfg('bucket') === '' || $this->cfg('endpoint') === '') {
            throw new ApiException('OSS 配置不完整（access_key_id / access_key_secret / bucket / endpoint）', 500);
        }
        return new \OSS\OssClient($id, $secret, $this->cfg('endpoint'));
    }

    private function cfg(string $key): string
    {
        return (string)config('plugin.cccms.filesystem.oss.' . $key, '');
    }
}
