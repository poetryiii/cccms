<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/** 字段级 encrypt 加解密（AES-256-GCM）。 */
final class Cipher
{
    /** 是否已配置 encrypt 密钥。未配置时字段级 encrypt 不可用（下发端据此跳过 crypto_key）。 */
    public static function configured(): bool
    {
        return (string)config('plugin.cccms.auth.data_encrypt_key', '') !== '';
    }

    private static function key(): string
    {
        $key = (string)config('plugin.cccms.auth.data_encrypt_key', '');
        if ($key === '') {
            throw new ApiException('data_encrypt_key 未配置', 500);
        }
        // 规整为 32 字节密钥
        return hash('sha256', $key, true);
    }

    /** 下发给前端的密钥（base64，32 字节）。仅用于 encrypt 字段的展示解密。 */
    public static function publicKey(): string
    {
        return base64_encode(self::key());
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 28) {
            throw new ApiException('密文格式非法', 500);
        }
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $cipher = substr($data, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new ApiException('解密失败', 500);
        }
        return $plain;
    }
}
