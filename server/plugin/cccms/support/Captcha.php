<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 图形验证码：SVG 生成（不依赖 GD 扩展），答案存 Redis。
 *
 * 校验后无论对错都立即删除，保证一次性使用，避免拿同一个验证码反复试密码。
 */
final class Captcha
{
    /** 去掉容易混淆的 0/O/1/I */
    private const CHARS = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LENGTH = 4;
    private const TTL = 300;
    private const KEY_PREFIX = 'cccms:captcha:';

    private const WIDTH = 120;
    private const HEIGHT = 40;

    /**
     * 生成一个验证码。
     *
     * @return array{id:string,image:string} image 为可直接放进 <img src> 的 data URI
     */
    public static function make(): array
    {
        $code = self::randomCode();
        $id   = bin2hex(random_bytes(16));

        try {
            Redis::setex(self::KEY_PREFIX . $id, self::TTL, strtolower($code));
        } catch (Throwable) {
            throw new ApiException('验证码服务不可用，请联系管理员', 500);
        }

        return ['id' => $id, 'image' => self::svg($code)];
    }

    /** 校验并作废；id 或输入为空直接失败 */
    public static function verify(string $id, string $input): bool
    {
        $input = strtolower(trim($input));
        if ($id === '' || $input === '') {
            return false;
        }

        $key = self::KEY_PREFIX . $id;
        try {
            $expect = Redis::get($key);
            Redis::del($key);
        } catch (Throwable) {
            return false;
        }

        return is_string($expect) && $expect !== '' && hash_equals($expect, $input);
    }

    private static function randomCode(): string
    {
        $max = strlen(self::CHARS) - 1;
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::CHARS[random_int(0, $max)];
        }
        return $out;
    }

    /** 生成带干扰线与噪点的 SVG，转成 base64 data URI */
    private static function svg(string $code): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $parts = [sprintf('<rect width="%d" height="%d" rx="4" fill="#f2f5fa"/>', $w, $h)];

        for ($i = 0; $i < 4; $i++) {
            $parts[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" opacity="0.45"/>',
                random_int(0, $w),
                random_int(0, $h),
                random_int(0, $w),
                random_int(0, $h),
                self::randColor(120, 205)
            );
        }

        for ($i = 0; $i < 22; $i++) {
            $parts[] = sprintf(
                '<circle cx="%d" cy="%d" r="1" fill="%s" opacity="0.55"/>',
                random_int(0, $w),
                random_int(0, $h),
                self::randColor(120, 210)
            );
        }

        $step = (int)floor(($w - 16) / self::LENGTH);
        foreach (str_split($code) as $index => $char) {
            $x = 12 + $index * $step;
            $y = random_int(27, 33);
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="Consolas,Menlo,monospace" font-size="25" font-weight="700" fill="%s" transform="rotate(%d %d %d)">%s</text>',
                $x,
                $y,
                self::randColor(25, 105),
                random_int(-24, 24),
                $x,
                $y,
                $char
            );
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">%s</svg>',
            $w,
            $h,
            $w,
            $h,
            implode('', $parts)
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function randColor(int $min, int $max): string
    {
        return sprintf('#%02x%02x%02x', random_int($min, $max), random_int($min, $max), random_int($min, $max));
    }
}
