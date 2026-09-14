<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 会话失效闸门。
 *
 * `TokenService` 是无状态 JWT（只签名、不在服务端存会话），所以「让已登录用户立刻下线」
 * 需要一个可全局比对的基准时间：**签发时间早于该基准的令牌一律作废**。
 *
 * 典型用途：开启维护模式时踢掉除超管以外的在线用户——
 * 超管豁免，否则维护期间没人能进系统。
 *
 * 存在 Redis（跨进程共享）；Redis 不可用时静默降级为「不做限制」，不影响可用性。
 * 分界线的 TTL 取令牌有效期：它要活到所有受影响的令牌自然过期为止。
 */
final class SessionGuard
{
    private const KEY = 'cccms:session:min_iat';

    /**
     * 记录一条分界线：此时间之前签发的令牌全部失效。
     *
     * @param int $at 0 = 当前时间
     * @return int 实际写入的时间戳
     */
    public static function cut(int $at = 0): int
    {
        $at = $at > 0 ? $at : time();

        try {
            Redis::setex(self::KEY, TokenService::ttl(), (string)$at);
        } catch (Throwable) {
            // Redis 不可用：降级为不限制，不能因为踢人就阻断业务
        }

        return $at;
    }

    /** 当前分界线；0 = 没有分界线（不限制任何令牌） */
    public static function cutoff(): int
    {
        try {
            $value = Redis::get(self::KEY);
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * 该签发时间的令牌是否已被分界线作废。
     *
     * 用 `<=` 而不是 `<`：包含「与分界线同一秒签发」的情况，
     * 否则刚好在同一秒登录成功的普通用户会成为漏网之鱼。
     * 拿不到 iat 的异常令牌同样视为失效（fail-closed）。
     */
    public static function isStale(int $iat): bool
    {
        $cutoff = self::cutoff();

        return $cutoff > 0 && $iat <= $cutoff;
    }

    /** 清除分界线（令牌不再被判定为失效） */
    public static function clear(): void
    {
        try {
            Redis::del(self::KEY);
        } catch (Throwable) {
            // 忽略
        }
    }
}
