<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SysConfig;
use Webman\Http\UploadFile;

/**
 * 腾讯云 COS 驱动。
 *
 * 依赖（按需安装）：composer require qcloud/cos-sdk-v5
 *
 * 凭证统一在后台「系统设置 → 配置管理 → 上传」维护（sys_config.upload.cos_*），
 * 密钥类项以密文存库，读取时自动解密。
 */
final class CosDriver extends StorageDriver
{
    /** 需解密的字段（后台以 `enc:` + Cipher 密文存储） */
    private const SECRET_KEYS = ['secret_key'];

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

    public function put(string $content, string $path): array
    {
        $path = $this->objectPath($path);
        $this->client()->putObject([
            'Bucket' => $this->bucket(),
            'Key'    => $path,
            'Body'   => $content,
        ]);

        return ['path' => $path, 'size' => strlen($content), 'hash' => sha1($content)];
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
        $name = 'upload.cos_' . $key;

        return in_array($key, self::SECRET_KEYS, true)
            ? SysConfig::getSecret($name)
            : SysConfig::getString($name);
    }
}
