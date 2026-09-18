<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 登录失败锁定。
 *
 * 规则来自 sys_config：
 *   security.login_fail_limit  —— 连续失败几次后锁定，0 表示关闭该机制
 *   security.login_fail_window —— 锁定/计数窗口，单位分钟
 *
 * Redis 不可用时按「不锁定」处理（fail-open）：限流属于加固手段，
 * 不应因为缓存故障把所有人挡在门外。配置读取失败同理。
 */
final class LoginThrottle
{
    private const KEY_PREFIX = 'cccms:login_fail:';

    /**
     * 剩余锁定时长（秒），0 表示未锁定。
     *
     * 必须同时判断「次数是否达到阈值」：失败计数 key 在第一次失败后就存在了，
     * 只看它的 TTL 会导致「错一次就锁一整个窗口」。
     */
    public static function lockedSeconds(string $username): int
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return 0;
        }

        try {
            $key   = self::key($username);
            $count = (int)Redis::get($key);
            if ($count < $limit) {
                return 0;
            }
            $ttl = (int)Redis::ttl($key);
        } catch (Throwable) {
            return 0;
        }

        return $ttl > 0 ? $ttl : 0;
    }

    /** 已锁定则抛 429 */
    public static function assertNotLocked(string $username): void
    {
        $left = self::lockedSeconds($username);
        if ($left > 0) {
            throw new ApiException(
                I18n::t('auth.too_many_attempts', ['minutes' => max(1, (int)ceil($left / 60))]),
                429
            );
        }
    }

    /** 记录一次失败；达到阈值后窗口重置为锁定时长 */
    public static function recordFailure(string $username): void
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return;
        }

        try {
            $key   = self::key($username);
            $count = (int)Redis::incr($key);
            if ($count === 1 || $count >= $limit) {
                Redis::expire($key, self::windowSeconds());
            }
        } catch (Throwable) {
            // 计数失败不影响登录主流程
        }
    }

    /** 剩余可尝试次数（用于提示） */
    public static function remainingAttempts(string $username): int
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }
        try {
            $count = (int)Redis::get(self::key($username));
        } catch (Throwable) {
            return $limit;
        }
        return max(0, $limit - $count);
    }

    /** 登录成功后清空计数 */
    public static function clear(string $username): void
    {
        try {
            Redis::del(self::key($username));
        } catch (Throwable) {
            // 忽略
        }
    }

    private static function limit(): int
    {
        return SysConfig::getInt('security.login_fail_limit', 5);
    }

    private static function windowSeconds(): int
    {
        return max(60, SysConfig::getInt('security.login_fail_window', 15) * 60);
    }

    private static function key(string $username): string
    {
        return self::KEY_PREFIX . strtolower($username);
    }
}
