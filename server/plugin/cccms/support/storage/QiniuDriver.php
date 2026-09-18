<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\ApiException;
use Webman\Http\UploadFile;

/**
 * 七牛云 Kodo 驱动。
 *
 * 依赖（按需安装）：composer require qiniu/php-sdk
 */
final class QiniuDriver extends StorageDriver
{
    public function upload(UploadFile $file): array
    {
        [$ext, $size] = $this->validate($file);

        $path = $this->objectName($ext);
        $local = (string)$file->getPathname();
        $hash = sha1_file($local) ?: '';

        [, $err] = $this->uploadManager()->putFile($this->uploadToken(), $path, $local);
        if ($err !== null) {
            throw new ApiException('七牛上传失败：' . (is_object($err) && method_exists($err, 'message') ? $err->message() : (string)$err), 500);
        }

        return $this->result($file, $path, $ext, $size, $hash);
    }

    public function put(string $content, string $path): array
    {
        $path = $this->objectPath($path);
        [, $err] = $this->uploadManager()->put($this->uploadToken(), $path, $content);
        if ($err !== null) {
            throw new ApiException('七牛上传失败：' . (is_object($err) && method_exists($err, 'message') ? $err->message() : (string)$err), 500);
        }

        return ['path' => $path, 'size' => strlen($content), 'hash' => sha1($content)];
    }

    public function delete(string $path): void
    {
        [, $err] = $this->bucketManager()->delete($this->cfg('bucket'), $path);
        if ($err !== null) {
            throw new ApiException('七牛删除失败：' . (is_object($err) && method_exists($err, 'message') ? $err->message() : (string)$err), 500);
        }
    }

    public function url(string $path): string
    {
        $domain = rtrim($this->cfg('domain'), '/');
        return $domain . '/' . ltrim($path, '/');
    }

    private function auth(): \Qiniu\Auth
    {
        if (!class_exists(\Qiniu\Auth::class)) {
            throw new ApiException('未安装七牛 SDK：composer require qiniu/php-sdk', 500);
        }
        $ak = $this->cfg('access_key');
        $sk = $this->cfg('secret_key');
        if ($ak === '' || $sk === '' || $this->cfg('bucket') === '' || $this->cfg('domain') === '') {
            throw new ApiException('七牛配置不完整（access_key / secret_key / bucket / domain）', 500);
        }
        return new \Qiniu\Auth($ak, $sk);
    }

    private function uploadToken(): string
    {
        return $this->auth()->uploadToken($this->cfg('bucket'));
    }

    private function uploadManager(): \Qiniu\Storage\UploadManager
    {
        return new \Qiniu\Storage\UploadManager();
    }

    private function bucketManager(): \Qiniu\Storage\BucketManager
    {
        return new \Qiniu\Storage\BucketManager($this->auth(), new \Qiniu\Config());
    }

    private function cfg(string $key): string
    {
        return (string)config('plugin.cccms.filesystem.qiniu.' . $key, '');
    }
}
