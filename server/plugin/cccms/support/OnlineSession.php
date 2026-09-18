<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 在线会话登记表（供「在线用户 / 强制下线」使用）。
 *
 * JWT 无状态，服务端本无会话可言；这里用 Redis 维护一份**辅助索引**：
 *
 *   - `cccms:online:s:{jti}`  Hash，一条会话的明细（用户、IP、UA、登录时间、最后活跃）；
 *   - `cccms:online:index`    ZSet，member = jti，score = 最后活跃时间（用于按活跃度倒序取列表）。
 *
 * 设计取舍：
 *   - **不维护「用户 → jti」集合**：在线人数本身有限，扫描索引比多维护一份易失配的集合更可靠；
 *   - `touch()` 有 60 秒节流：活跃时间没必要每请求刷新，避免把 Redis 写放大成每请求一次写；
 *   - 索引 ZSet **不设 TTL**（它会比单条会话活得久），过期成员由 `prune()` 惰性清理；
 *   - Redis 不可用时全部方法静默降级 —— 在线列表属于可观测能力，不该因为缓存故障影响业务。
 */
final class OnlineSession
{
    private const SESSION_PREFIX = 'cccms:online:s:';
    private const INDEX_KEY      = 'cccms:online:index';

    /** 活跃时间刷新间隔（秒）：小于该间隔的请求不写 Redis */
    private const TOUCH_INTERVAL = 60;

    /** 单次列表扫描的索引上限，防止异常情况下键无限增长导致慢查询 */
    private const SCAN_LIMIT = 2000;

    /**
     * 登录成功后登记会话。
     *
     * @return void
     */
    public static function register(
        int $userId,
        string $username,
        string $nickname,
        string $jti,
        string $ip = '',
        string $ua = '',
    ): void {
        if ($jti === '' || $userId <= 0) {
            return;
        }

        $now = time();
        $ttl = TokenService::ttl();

        try {
            $key = self::SESSION_PREFIX . $jti;
            Redis::hMSet($key, [
                'user_id'  => (string)$userId,
                'username' => $username,
                'nickname' => $nickname,
                'jti'      => $jti,
                'ip'       => $ip,
                'ua'       => mb_substr($ua, 0, 255),
                'login_at' => (string)$now,
                'last_at'  => (string)$now,
            ]);
            Redis::expire($key, $ttl);
            Redis::zAdd(self::INDEX_KEY, $now, $jti);
        } catch (Throwable) {
            // 降级：不登记
        }
    }

    /**
     * 刷新活跃时间（带 60 秒节流）。
     *
     * 会话不存在时直接返回：可能已过期或被清理，不重新创建。
     */
    public static function touch(string $jti): void
    {
        if ($jti === '') {
            return;
        }

        try {
            $key = self::SESSION_PREFIX . $jti;
            $last = Redis::hGet($key, 'last_at');
            if ($last === false || $last === null) {
                return;
            }

            $now = time();
            if ($now - (int)$last < self::TOUCH_INTERVAL) {
                return;
            }

            Redis::hSet($key, 'last_at', (string)$now);
            Redis::expire($key, TokenService::ttl());
            Redis::zAdd(self::INDEX_KEY, $now, $jti);
        } catch (Throwable) {
            // 降级
        }
    }

    /** 注销单条会话（登出时调用） */
    public static function remove(string $jti): void
    {
        if ($jti === '') {
            return;
        }

        try {
            Redis::del(self::SESSION_PREFIX . $jti);
            Redis::zRem(self::INDEX_KEY, $jti);
        } catch (Throwable) {
            // 降级
        }
    }

    /**
     * 移除某个用户的全部在线会话。
     *
     * 注意：本方法只负责「从在线列表移除」，真正的**令牌作废**由调用方
     * 配合 `TokenBlacklist::revokeUser()` 完成 —— 两者是互补的，缺一不可。
     *
     * @return int 移除的会话数
     */
    public static function removeUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $removed = 0;
        foreach (self::entries() as $jti => $row) {
            if ((int)($row['user_id'] ?? 0) !== $userId) {
                continue;
            }
            try {
                Redis::del(self::SESSION_PREFIX . $jti);
                Redis::zRem(self::INDEX_KEY, $jti);
            } catch (Throwable) {
                // 忽略单条失败，继续处理其余会话
            }
            $removed++;
        }

        return $removed;
    }

    /**
     * 分页查询在线会话（按最后活跃时间倒序）。
     *
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public static function paginate(string $keyword = '', int $page = 1, int $limit = 15): array
    {
        $rows = [];
        foreach (self::entries() as $jti => $row) {
            $item = [
                'jti'      => $jti,
                'user_id'  => (int)($row['user_id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'ip'       => (string)($row['ip'] ?? ''),
                'ua'       => (string)($row['ua'] ?? ''),
                'login_at' => self::date((int)($row['login_at'] ?? 0)),
                'last_at'  => self::date((int)($row['last_at'] ?? 0)),
                '_last'    => (int)($row['last_at'] ?? 0),
            ];

            if ($keyword !== ''
                && !str_contains($item['username'], $keyword)
                && !str_contains($item['nickname'], $keyword)
                && !str_contains($item['ip'], $keyword)) {
                continue;
            }

            $rows[] = $item;
        }

        usort($rows, static fn (array $a, array $b): int => $b['_last'] <=> $a['_last']);

        $total  = count($rows);
        $page   = max(1, $page);
        $limit  = max(1, $limit);
        $offset = ($page - 1) * $limit;
        $list   = array_slice($rows, $offset, $limit);

        foreach ($list as &$item) {
            unset($item['_last']);
        }
        unset($item);

        return ['total' => $total, 'list' => $list];
    }

    /** 在线会话总数 */
    public static function count(): int
    {
        return count(self::entries());
    }

    /**
     * 清理已过期（明细键已消失）的索引成员。
     *
     * @return int 清理条数
     */
    public static function prune(): int
    {
        $cleaned = 0;
        $start   = 0;

        do {
            try {
                $members = Redis::zRange(self::INDEX_KEY, $start, $start + 99);
            } catch (Throwable) {
                return $cleaned;
            }

            if (!is_array($members) || $members === []) {
                break;
            }

            foreach ($members as $jti) {
                try {
                    if (!Redis::exists(self::SESSION_PREFIX . $jti)) {
                        Redis::zRem(self::INDEX_KEY, $jti);
                        $cleaned++;
                    }
                } catch (Throwable) {
                    return $cleaned;
                }
            }

            $start += 100;
            if ($start > self::SCAN_LIMIT) {
                break;
            }
        } while (true);

        return $cleaned;
    }

    /**
     * 读取索引并组装「jti => 明细」，顺带清理失效成员。
     *
     * @return array<string,array<string,mixed>>
     */
    private static function entries(): array
    {
        try {
            $members = Redis::zRevRange(self::INDEX_KEY, 0, self::SCAN_LIMIT - 1);
            $scores  = Redis::zRevRange(self::INDEX_KEY, 0, self::SCAN_LIMIT - 1, true);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($members) || $members === []) {
            return [];
        }

        $map = [];
        foreach ($members as $jti) {
            try {
                $row = Redis::hGetAll(self::SESSION_PREFIX . $jti);
            } catch (Throwable) {
                return $map;
            }

            if (!is_array($row) || $row === []) {
                // 会话已过期：索引里留着僵尸成员，顺手清掉
                try {
                    Redis::zRem(self::INDEX_KEY, $jti);
                } catch (Throwable) {
                    // 忽略
                }
                continue;
            }

            // score 是最后活跃时间，比 hash 字段更可靠（touch 节流期间 hash 可能未更新）
            if (is_array($scores) && isset($scores[$jti])) {
                $row['last_at'] = (int)$scores[$jti];
            }

            $map[(string)$jti] = $row;
        }

        return $map;
    }

    private static function date(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
