<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 登录失败锁定（**双维度**）。
 *
 * 规则来自 sys_config：
 *   security.login_fail_limit    —— 同一**账号**连续失败几次后锁定，0 表示关闭
 *   security.login_fail_ip_limit —— 同一**IP** 连续失败几次后限流，0 表示关闭
 *   security.login_fail_window   —— 计数 / 锁定窗口，单位分钟
 *
 * 为什么必须分两个维度：只按账号计数时，攻击者知道管理员用户名后故意失败 N 次
 * 就能把对方**锁在门外**（低成本拒绝服务）；而分布式撞库（每个账号只试 2 次）
 * 反而完全不触发。因此：
 *   - 账号维度超限 → 锁定该账号（防定向爆破）；
 *   - IP 维度超限   → 限流该 IP（防撞库），**不锁账号**，避免误伤同出口 IP 的同事。
 *
 * Redis 不可用时按「不锁定」处理（fail-open）：限流属于加固手段，
 * 不应因为缓存故障把所有人挡在门外。配置读取失败同理。
 */
final class LoginThrottle
{
    private const USER_PREFIX = 'cccms:login_fail:user:';
    private const IP_PREFIX   = 'cccms:login_fail:ip:';

    /** 剩余锁定 / 限流秒数（账号或 IP 维度，取较大者）；0 表示未触发 */
    public static function lockedSeconds(string $username, string $ip = ''): int
    {
        $left = self::lockLeft(self::USER_PREFIX . strtolower($username), self::limit());
        if ($left > 0) {
            return $left;
        }

        return $ip === '' ? 0 : self::lockLeft(self::IP_PREFIX . $ip, self::ipLimit());
    }

    /** 已锁定 / 已限流则抛 429 */
    public static function assertNotLocked(string $username, string $ip = ''): void
    {
        $left = self::lockedSeconds($username, $ip);
        if ($left > 0) {
            throw new ApiException(
                I18n::t('auth.too_many_attempts', ['minutes' => max(1, (int)ceil($left / 60))]),
                429
            );
        }
    }

    /** 记录一次失败；两个维度各自计数，达到阈值后窗口重置为锁定时长 */
    public static function recordFailure(string $username, string $ip = ''): void
    {
        self::bump(self::USER_PREFIX . strtolower($username), self::limit());
        if ($ip !== '') {
            self::bump(self::IP_PREFIX . $ip, self::ipLimit());
        }
    }

    /** 账号维度剩余可尝试次数（用于提示） */
    public static function remainingAttempts(string $username): int
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }
        try {
            $count = (int)Redis::get(self::USER_PREFIX . strtolower($username));
        } catch (Throwable) {
            return $limit;
        }

        return max(0, $limit - $count);
    }

    /** 登录成功后清空两个维度的计数（IP 维度一并清，避免正常用户被历史失败拖累） */
    public static function clear(string $username, string $ip = ''): void
    {
        try {
            Redis::del(self::USER_PREFIX . strtolower($username));
            if ($ip !== '') {
                Redis::del(self::IP_PREFIX . $ip);
            }
        } catch (Throwable) {
            // 忽略
        }
    }

    /**
     * 达到阈值才算锁定。
     *
     * 必须同时判断「次数是否达到阈值」：失败计数 key 在第一次失败后就存在了，
     * 只看它的 TTL 会导致「错一次就锁一整个窗口」。
     */
    private static function lockLeft(string $key, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        try {
            if ((int)Redis::get($key) < $limit) {
                return 0;
            }
            $ttl = (int)Redis::ttl($key);
        } catch (Throwable) {
            return 0;
        }

        return $ttl > 0 ? $ttl : 0;
    }

    private static function bump(string $key, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        try {
            $count = (int)Redis::incr($key);
            if ($count === 1 || $count >= $limit) {
                Redis::expire($key, self::windowSeconds());
            }
        } catch (Throwable) {
            // 计数失败不影响登录主流程
        }
    }

    private static function limit(): int
    {
        return SysConfig::getInt('security.login_fail_limit', 5);
    }

    private static function ipLimit(): int
    {
        return SysConfig::getInt('security.login_fail_ip_limit', 20);
    }

    private static function windowSeconds(): int
    {
        return max(60, SysConfig::getInt('security.login_fail_window', 15) * 60);
    }
}
