<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\ApiException;
use Webman\Http\UploadFile;

/**
 * 腾讯云 COS 驱动。
 *
 * 依赖（按需安装）：composer require qcloud/cos-sdk-v5
 */
final class CosDriver extends StorageDriver
{
    public function upload(UploadFile $file): array
    {
        [$ext, $size] = $this->validate($file);

        $path = $this->objectName($ext);
        $local = (string)$file->getPathname();
        $hash = sha1_file($local) ?: '';

        $this->client()->putObject([
            'Bucket' => $this->bucket(),
            'Key'    => $path,
            'Body'   => fopen($local, 'rb'),
        ]);

        return $this->result($file, $path, $ext, $size, $hash);
    }

    public function delete(string $path): void
    {
        $this->client()->deleteObject([
            'Bucket' => $this->bucket(),
            'Key'    => $path,
        ]);
    }

    public function url(string $path): string
    {
        $domain = rtrim($this->cfg('domain'), '/');
        if ($domain !== '') {
            return $domain . '/' . ltrim($path, '/');
        }
        return sprintf(
            'https://%s.cos.%s.myqcloud.com/%s',
            $this->cfg('bucket'),
            $this->cfg('region'),
            ltrim($path, '/')
        );
    }

    private function client(): \Qcloud\Cos\Client
    {
        if (!class_exists(\Qcloud\Cos\Client::class)) {
            throw new ApiException('未安装腾讯云 COS SDK：composer require qcloud/cos-sdk-v5', 500);
        }
        $id = $this->cfg('secret_id');
        $key = $this->cfg('secret_key');
        if ($id === '' || $key === '' || $this->cfg('bucket') === '' || $this->cfg('region') === '') {
            throw new ApiException('COS 配置不完整（secret_id / secret_key / bucket / region）', 500);
        }
        return new \Qcloud\Cos\Client([
            'region'      => $this->cfg('region'),
            'credentials' => ['secretId' => $id, 'secretKey' => $key],
        ]);
    }

    private function bucket(): string
    {
        return $this->cfg('bucket') . '-' . $this->cfg('region');
    }

    private function cfg(string $key): string
    {
        return (string)config('plugin.cccms.filesystem.cos.' . $key, '');
    }
}
