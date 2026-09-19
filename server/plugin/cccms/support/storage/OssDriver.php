<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SysConfig;
use Webman\Http\UploadFile;

/**
 * 阿里云 OSS 驱动。
 *
 * 依赖（按需安装）：composer require aliyuncs/oss-sdk-php
 *
 * 凭证统一在后台「系统设置 → 配置管理 → 上传」维护（sys_config.upload.oss_*），
 * 密钥类项以密文存库，读取时自动解密。
 */
final class OssDriver extends StorageDriver
{
    /** 需解密的字段（后台以 `enc:` + Cipher 密文存储） */
    private const SECRET_KEYS = ['access_key_secret'];

    public function upload(UploadFile $file): array
    {
        [$ext, $size] = $this->validate($file);

        $path = $this->objectName($ext);
        $local = (string)$file->getPathname();
        $hash = sha1_file($local) ?: '';

        $this->client()->putObject($this->cfg('bucket'), $path, $local);

        return $this->result($file, $path, $ext, $size, $hash);
    }

    public function put(string $content, string $path): array
    {
        $path = $this->objectPath($path);
        $this->client()->putObject($this->cfg('bucket'), $path, $content);

        return ['path' => $path, 'size' => strlen($content), 'hash' => sha1($content)];
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
        $name = 'upload.oss_' . $key;

        return in_array($key, self::SECRET_KEYS, true)
            ? SysConfig::getSecret($name)
            : SysConfig::getString($name);
    }
}
