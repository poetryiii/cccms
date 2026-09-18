<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 令牌失效名单。
 *
 * JWT 是无状态的：签名有效即通过，服务端没有「会话」可以销毁。所以「登出」与
 * 「强制下线」必须自己记一份失效依据，本类提供两种粒度：
 *
 *   1. **单令牌**（`revoke()`）：按 `jti` 精确作废 —— 登出时使用；
 *   2. **用户级分界线**（`revokeUser()`）：比该时间早签发的**该用户**令牌全部作废 ——
 *      「强制下线」使用。与全局的 `SessionGuard` 是同一套思路，只是作用域收窄到单个用户。
 *
 * 都存 Redis（跨进程共享）。Redis 不可用时**静默降级为不拦截**：
 * 与 `SessionGuard` / `LoginThrottle` 的取舍一致 —— 失效名单属于加固层，
 * 不应因为缓存故障把所有人挡在门外（JWT 自身的签名与过期校验始终生效）。
 */
final class TokenBlacklist
{
    private const JTI_PREFIX  = 'cccms:token:bl:';
    private const USER_PREFIX = 'cccms:token:cut:';

    /** 作废单个令牌；TTL 取「剩余有效期」，令牌自然过期后键自动消失，不留垃圾 */
    public static function revoke(string $jti, int $expiresAt = 0): void
    {
        if ($jti === '') {
            return;
        }

        $ttl = $expiresAt > 0 ? $expiresAt - time() : TokenService::ttl();
        if ($ttl <= 0) {
            return;   // 已过期，无需记录
        }

        try {
            Redis::setex(self::JTI_PREFIX . $jti, $ttl, '1');
        } catch (Throwable) {
            // 降级：不拦截
        }
    }

    /** 该 jti 是否已被作废 */
    public static function isRevoked(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        try {
            return (bool)Redis::exists(self::JTI_PREFIX . $jti);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 记录用户级分界线：该用户在此时间之前签发的令牌全部作废。
     *
     * @param int $at 0 = 当前时间
     * @return int 实际写入的时间戳
     */
    public static function revokeUser(int $userId, int $at = 0): int
    {
        $at = $at > 0 ? $at : time();

        try {
            Redis::setex(self::USER_PREFIX . $userId, TokenService::ttl(), (string)$at);
        } catch (Throwable) {
            // 降级
        }

        return $at;
    }

    /** 用户级分界线；0 = 不限制 */
    public static function userCutoff(int $userId): int
    {
        try {
            $value = Redis::get(self::USER_PREFIX . $userId);
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * 该签发时间对应用户的令牌是否已被分界线作废。
     *
     * 用 `<=` 而不是 `<`：包含「与分界线同一秒签发」的情况，
     * 否则刚好在同一秒签发的令牌会成为漏网之鱼（与 `SessionGuard` 同一约定）。
     */
    public static function isUserStale(int $userId, int $iat): bool
    {
        $cutoff = self::userCutoff($userId);

        return $cutoff > 0 && $iat <= $cutoff;
    }

    /** 综合判断：单令牌黑名单 + 用户级分界线 */
    public static function claimsRevoked(array $claims): bool
    {
        if (self::isRevoked((string)($claims['jti'] ?? ''))) {
            return true;
        }

        $userId = (int)($claims['sub'] ?? 0);

        return $userId > 0 && self::isUserStale($userId, (int)($claims['iat'] ?? 0));
    }

    /** 清除用户级分界线（用户重新登录后不再拦截） */
    public static function clearUser(int $userId): void
    {
        try {
            Redis::del(self::USER_PREFIX . $userId);
        } catch (Throwable) {
            // 忽略
        }
    }
}
