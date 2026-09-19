<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 接口限流（Redis 固定窗口计数）。
 *
 * 为什么需要：导出 / 导入 / 代码生成这类接口单次开销远大于普通查询（大结果集、文件流、
 * 落盘写代码），而 webman 是常驻进程 —— 单个账号高频刷取会占满 worker，
 * 拖慢所有在线用户。登录接口已有独立的失败锁定（见 LoginThrottle），这里覆盖**其余全部接口**。
 *
 * 计数维度：**用户 + 路由**。不按 IP：后台系统常见同出口 IP（办公室 NAT），
 * 按 IP 会互相误伤；用户维度也天然区分了「一个人刷」与「很多人正常用」。
 *
 * 窗口用**固定窗口**而不是令牌桶：固定窗口用一次 `INCR` + 一次 `EXPIRE` 就能实现，
 * 且天然原子（无需 Lua 脚本，跨 phpredis / predis 行为一致）；令牌桶能削峰但需要脚本或
 * 读改写，对「防刷」这个目标收益有限。固定窗口的已知缺点（窗口边界处可能瞬时翻倍）
 * 对限流目的可以接受。
 *
 * Redis 不可用时 **fail-open**（放行）：限流属于加固层，与 LoginThrottle / TokenBlacklist
 * 取舍一致 —— 不应因为缓存故障把所有人挡在门外。
 */
final class RateLimiter
{
    private const PREFIX = 'cccms:rate:';

    /** 重接口：路径以此结尾的接口单独收紧阈值（导出 / 导入 / 代码生成） */
    private const HEAVY_PATTERN = '#/(export|import|generate)$#';

    /**
     * 计数一次并判定是否超限。
     *
     * @param string $route 路由标识（用户 ID + 路径，调用方拼好）
     * @param bool   $heavy 是否重接口（用更严的阈值）
     * @return array{allowed:bool,retry_after:int,limit:int}
     */
    public static function hit(string $route, bool $heavy): array
    {
        if (!SysConfig::getBool('security.rate_limit_enable', true)) {
            return ['allowed' => true, 'retry_after' => 0, 'limit' => 0];
        }

        $limit = self::limitFor($heavy);
        if ($limit <= 0) {
            return ['allowed' => true, 'retry_after' => 0, 'limit' => 0];
        }

        $window = self::windowSeconds();
        $key    = self::PREFIX . $window . ':' . $route;
        $count  = self::increment($key, $window);

        // Redis 不可用 → fail-open
        if ($count === null) {
            return ['allowed' => true, 'retry_after' => 0, 'limit' => $limit];
        }

        if (!self::exceeded($count, $limit)) {
            return ['allowed' => true, 'retry_after' => 0, 'limit' => $limit];
        }

        return [
            'allowed'     => false,
            'retry_after' => self::retryAfter($key, $window),
            'limit'       => $limit,
        ];
    }

    /** 是否重接口（纯函数，便于单测） */
    public static function isHeavy(string $path): bool
    {
        return preg_match(self::HEAVY_PATTERN, '/' . ltrim($path, '/')) === 1;
    }

    /** 阈值：重接口取 security.rate_limit_heavy_limit，其余取 security.rate_limit_limit；0 = 关闭 */
    public static function limitFor(bool $heavy): int
    {
        return $heavy
            ? SysConfig::getInt('security.rate_limit_heavy_limit', 5)
            : SysConfig::getInt('security.rate_limit_limit', 60);
    }

    /** 窗口长度（秒），最小 1 秒，避免配成 0 导致每个请求都自成一个窗口 */
    public static function windowSeconds(): int
    {
        return max(1, SysConfig::getInt('security.rate_limit_window', 60));
    }

    /** 是否超限（纯函数，便于单测）：limit <= 0 视为不限制 */
    public static function exceeded(int $count, int $limit): bool
    {
        return $limit > 0 && $count > $limit;
    }

    /**
     * 自增计数并保证窗口 TTL 存在。
     *
     * 只在第一次（count === 1）设置过期：后续自增**不刷新 TTL**，
     * 否则持续请求会让窗口无限后延，退化成「永不重置」。
     *
     * @return int|null 计数；Redis 不可用返回 null
     */
    private static function increment(string $key, int $window): ?int
    {
        try {
            $count = (int)Redis::incr($key);
            if ($count === 1) {
                Redis::expire($key, $window);
            }

            return $count;
        } catch (Throwable) {
            return null;
        }
    }

    /** 剩余等待秒数（供 Retry-After）；取不到 TTL 时按整窗口兜底 */
    private static function retryAfter(string $key, int $window): int
    {
        try {
            $ttl = (int)Redis::ttl($key);
        } catch (Throwable) {
            return $window;
        }

        return $ttl > 0 ? $ttl : $window;
    }
}
