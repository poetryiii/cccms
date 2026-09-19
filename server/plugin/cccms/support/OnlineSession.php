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
 *   - `cccms:online:s:{jti}`  Hash，一条会话的明细（用户、IP、UA、登录时间、最后活跃、过期时间）；
 *     设备类型 / 操作系统 / 浏览器由 UA 在读取时派生，不单独落库；
 *   - `cccms:online:index`    ZSet，member = jti，score = 最后活跃时间（按活跃度倒序取列表）。
 *
 * 设计取舍：
 *   - **不维护「用户 → jti」集合**：在线人数本身有限，扫描索引比多维护一份易失配的集合更可靠；
 *   - `touch()` 有 60 秒节流：活跃时间没必要每请求刷新，避免把 Redis 写放大成每请求一次写；
 *   - 索引 ZSet **不设 TTL**（它会比单条会话活得久），过期成员由 `prune()` 惰性清理；
 *   - Redis 不可用时全部方法静默降级 —— 在线列表属于可观测能力，不该因为缓存故障影响业务。
 *
 * 列表查询分两条路径（**量级扩展**）：
 *   - **无关键字**：走 Redis **服务端分页**（`zRevRange` 的 offset/limit），total 用 `zCard`，
 *     不把全量成员拉进 PHP 内存，支持任意量级；
 *   - **有关键字**：过滤字段在 Hash 里，Redis 无法按这些字段建索引，改用**分段扫描**
 *     （`zRevRange` 每段 `SCAN_BATCH` 条，取代历史「最多 2000 条」的硬上限），应用层过滤后内存分页。
 */
final class OnlineSession
{
    private const SESSION_PREFIX = 'cccms:online:s:';
    private const INDEX_KEY      = 'cccms:online:index';

    /** 活跃时间刷新间隔（秒）：小于该间隔的请求不写 Redis */
    private const TOUCH_INTERVAL = 60;

    /** 分段扫描批大小：有关键字过滤 / 清理 / 下线时按这个粒度拉取，避免一次性拉全量 */
    private const SCAN_BATCH = 200;

    /**
     * 登录成功后登记会话。
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
                // 令牌过期时间：强下线黑名单据此精确计算 TTL，不再按整个有效期兜底
                'exp'      => (string)($now + $ttl),
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
     * 注意只刷新活跃时间与 Hash 存活期，**不**延长令牌过期时间（`exp` 是签发时固定的）。
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
     * 滑动续期：把一条在线会话迁到新的 jti（保留登录时间与终端信息）。
     *
     * 续期后令牌的 jti 变了，而在线列表与「强制下线」都以 jti 为准 —— 不迁移的话，
     * 管理员在列表里踢掉的是**已经废弃的旧令牌**，用户手里的新令牌依然有效。
     *
     * 会话不存在（未登记 / Redis 不可用）时静默跳过，**不新建**：登录时登记是唯一入口。
     */
    public static function rotate(string $oldJti, string $newJti, int $exp): void
    {
        if ($oldJti === '' || $newJti === '' || $oldJti === $newJti) {
            return;
        }

        try {
            $key = self::SESSION_PREFIX . $oldJti;
            $row = Redis::hGetAll($key);
            if (!is_array($row) || $row === []) {
                return;
            }

            $now = time();
            $row['jti']     = $newJti;
            $row['last_at'] = (string)$now;
            $row['exp']     = (string)$exp;

            $newKey = self::SESSION_PREFIX . $newJti;
            Redis::hMSet($newKey, $row);
            Redis::expire($newKey, max(1, $exp - $now));
            Redis::zAdd(self::INDEX_KEY, $now, $newJti);

            Redis::del($key);
            Redis::zRem(self::INDEX_KEY, $oldJti);
        } catch (Throwable) {
            // 降级：不迁移。旧会话仍在，最坏是列表里短暂显示旧令牌
        }
    }

    /** 单条会话的令牌过期时间；0 = 会话不存在或无法读取 */
    public static function expiryOf(string $jti): int
    {
        if ($jti === '') {
            return 0;
        }

        try {
            $exp = Redis::hGet(self::SESSION_PREFIX . $jti, 'exp');
            return is_numeric($exp) ? (int)$exp : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 某个用户的全部在线会话（按最后活跃时间倒序）。
     *
     * 供「我的登录设备」自助管理使用：只返回该用户的会话，不暴露他人。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listOfUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $list = [];
        self::scanAll(static function (string $jti, array $row) use ($userId, &$list): void {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $list[] = self::decorate($jti, $row);
            }
        });

        return $list;
    }

    /** 单条会话归属的用户 ID；0 = 会话不存在或无法读取 */
    public static function ownerOf(string $jti): int
    {
        if ($jti === '') {
            return 0;
        }

        try {
            $userId = Redis::hGet(self::SESSION_PREFIX . $jti, 'user_id');
            return is_numeric($userId) ? (int)$userId : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 某用户全部在线会话的最晚令牌过期时间；0 = 无在线会话。
     *
     * 供「强制下线」精确设置用户级黑名单 TTL：分界线只需保留到最后一个旧令牌过期。
     */
    public static function maxExpiryOfUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $max = 0;
        self::scanAll(static function (string $jti, array $row) use ($userId, &$max): void {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $max = max($max, (int)($row['exp'] ?? 0));
            }
        });

        return $max;
    }

    /**
     * 移除某个用户的全部在线会话。
     *
     * 注意：本方法只负责「从在线列表移除」，真正的**令牌作废**由调用方
     * 配合 `TokenBlacklist::revokeUser()` 完成 —— 两者互补，缺一不可。
     *
     * @return int 移除的会话数
     */
    public static function removeUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        // 先收集再删除：遍历过程中删除成员会让 ZSet 的 offset 偏移，导致漏扫
        $toRemove = [];
        self::scanAll(static function (string $jti, array $row) use ($userId, &$toRemove): void {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $toRemove[] = $jti;
            }
        });

        foreach ($toRemove as $jti) {
            try {
                Redis::del(self::SESSION_PREFIX . $jti);
                Redis::zRem(self::INDEX_KEY, $jti);
            } catch (Throwable) {
                // 忽略单条失败，继续处理其余会话
            }
        }

        return count($toRemove);
    }

    /**
     * 分页查询在线会话（按最后活跃时间倒序）。
     *
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public static function paginate(string $keyword = '', int $page = 1, int $limit = 15): array
    {
        $page  = max(1, $page);
        $limit = max(1, $limit);

        if (trim($keyword) === '') {
            return self::paginatePlain($page, $limit);
        }

        return self::paginateFiltered(trim($keyword), $page, $limit);
    }

    /** 在线会话总数（近似：含少量待清理的过期索引，由 `prune()` 定期收敛） */
    public static function count(): int
    {
        try {
            return (int)Redis::zCard(self::INDEX_KEY);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 清理已过期（明细键已消失）的索引成员。
     *
     * @return int 清理条数
     */
    public static function prune(): int
    {
        $toRemove = [];
        $start    = 0;

        while (true) {
            try {
                $members = Redis::zRange(self::INDEX_KEY, $start, $start + self::SCAN_BATCH - 1);
            } catch (Throwable) {
                break;
            }

            if (!is_array($members) || $members === []) {
                break;
            }

            foreach ($members as $jti) {
                $jti = (string)$jti;
                try {
                    if (!Redis::exists(self::SESSION_PREFIX . $jti)) {
                        $toRemove[] = $jti;
                    }
                } catch (Throwable) {
                    break 2;
                }
            }

            if (count($members) < self::SCAN_BATCH) {
                break;
            }
            $start += self::SCAN_BATCH;
        }

        foreach ($toRemove as $jti) {
            try {
                Redis::zRem(self::INDEX_KEY, $jti);
            } catch (Throwable) {
                // 忽略
            }
        }

        return count($toRemove);
    }

    // ------------------------------------------------------------------
    // 私有
    // ------------------------------------------------------------------

    /** 无关键字：Redis 服务端分页。 */
    private static function paginatePlain(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        try {
            $total   = (int)Redis::zCard(self::INDEX_KEY);
            $members = Redis::zRevRange(self::INDEX_KEY, $offset, $offset + $limit - 1);
        } catch (Throwable) {
            return ['total' => 0, 'list' => []];
        }

        return ['total' => $total, 'list' => self::hydrate(is_array($members) ? $members : [])];
    }

    /** 有关键字：分段扫描 + 应用层过滤（字段在 Hash 里，无法用 ZSet 建索引）。 */
    private static function paginateFiltered(string $keyword, int $page, int $limit): array
    {
        $offset  = ($page - 1) * $limit;
        $matched = [];

        self::scanAll(static function (string $jti, array $row) use ($keyword, &$matched): void {
            if (self::match($row, $keyword)) {
                $matched[] = self::decorate($jti, $row);
            }
        });

        $total = count($matched);

        return ['total' => $total, 'list' => array_slice($matched, $offset, $limit)];
    }

    /**
     * 分段遍历全部索引成员（按活跃倒序），对每条**存在**的会话回调。
     *
     * 读到已过期的会话（Hash 已消失）时只跳过、不删除 —— 遍历过程中删除成员
     * 会改变 ZSet 的 offset，导致漏扫；清理交给独立的 `prune()`。
     *
     * @param callable(string,array<string,mixed>):void $visitor
     */
    private static function scanAll(callable $visitor): void
    {
        $start = 0;

        while (true) {
            try {
                $members = Redis::zRevRange(self::INDEX_KEY, $start, $start + self::SCAN_BATCH - 1);
            } catch (Throwable) {
                return;
            }

            if (!is_array($members) || $members === []) {
                return;
            }

            foreach ($members as $jti) {
                $jti = (string)$jti;
                $row = self::rawRow($jti);
                if ($row === null) {
                    continue;
                }
                $visitor($jti, $row);
            }

            if (count($members) < self::SCAN_BATCH) {
                return;
            }
            $start += self::SCAN_BATCH;
        }
    }

    /** 一批 jti → 已格式化列表（跳过已过期的僵尸会话）。 */
    private static function hydrate(array $jtis): array
    {
        $list = [];
        foreach ($jtis as $jti) {
            $jti = (string)$jti;
            $row = self::rawRow($jti);
            if ($row === null) {
                continue;
            }
            $list[] = self::decorate($jti, $row);
        }

        return $list;
    }

    /**
     * 读取单条会话明细；会话已过期（Hash 消失）返回 null。
     *
     * @return array<string,mixed>|null
     */
    private static function rawRow(string $jti): ?array
    {
        try {
            $row = Redis::hGetAll(self::SESSION_PREFIX . $jti);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($row) || $row === []) {
            return null;
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function decorate(string $jti, array $row): array
    {
        $ua     = (string)($row['ua'] ?? '');
        $client = self::parseUa($ua);

        return [
            'jti'       => $jti,
            'user_id'   => (int)($row['user_id'] ?? 0),
            'username'  => (string)($row['username'] ?? ''),
            'nickname'  => (string)($row['nickname'] ?? ''),
            'ip'        => (string)($row['ip'] ?? ''),
            'ua'        => $ua,
            'device'    => $client['device'],
            'os'        => $client['os'],
            'browser'   => $client['browser'],
            'login_at'  => self::date((int)($row['login_at'] ?? 0)),
            'last_at'   => self::date((int)($row['last_at'] ?? 0)),
            // 令牌过期时间（签发时固定，`touch()` 不延长）
            'expire_at' => self::date((int)($row['exp'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function match(array $row, string $keyword): bool
    {
        if (
            str_contains((string)($row['username'] ?? ''), $keyword)
            || str_contains((string)($row['nickname'] ?? ''), $keyword)
            || str_contains((string)($row['ip'] ?? ''), $keyword)
        ) {
            return true;
        }

        // 终端信息由 UA 派生，Redis 里没有独立字段，只能在这里逐个比对
        $client = self::parseUa((string)($row['ua'] ?? ''));

        return str_contains($client['device'], $keyword)
            || str_contains($client['os'], $keyword)
            || str_contains($client['browser'], $keyword);
    }

    /**
     * 从 UA 派生终端信息（设备类型 / 操作系统 / 浏览器）。
     *
     * 只存 UA、展示时再派生：旧会话无需迁移，UA 解析规则调整后历史记录同样生效。
     *
     * @return array{device:string,os:string,browser:string}
     */
    private static function parseUa(string $ua): array
    {
        $s = strtolower($ua);

        $device = '电脑';
        if ($s === '') {
            $device = '未知';
        } elseif (self::containsAny($s, ['bot', 'spider', 'crawler', 'curl/', 'wget/', 'python-requests', 'headless'])) {
            $device = '爬虫';
        } elseif (str_contains($s, 'ipad') || (str_contains($s, 'android') && !str_contains($s, 'mobile'))) {
            $device = '平板';
        } elseif (self::containsAny($s, ['mobile', 'iphone', 'ipod', 'android', 'windows phone'])) {
            $device = '移动端';
        }

        $os = '';
        if (self::containsAny($s, ['iphone', 'ipad', 'ipod'])) {
            $os = 'iOS';
        } elseif (str_contains($s, 'harmony')) {
            $os = 'HarmonyOS';
        } elseif (str_contains($s, 'android')) {
            $os = 'Android';
        } elseif (str_contains($s, 'windows')) {
            $os = 'Windows';
        } elseif (str_contains($s, 'mac os x') || str_contains($s, 'macintosh')) {
            $os = 'macOS';
        } elseif (str_contains($s, 'linux')) {
            $os = 'Linux';
        }

        return ['device' => $device, 'os' => $os, 'browser' => self::detectBrowser($s)];
    }

    /** @param string $s 已转小写的 UA；顺序敏感：微信/Edge 等的 UA 里同时含有 Chrome/Safari */
    private static function detectBrowser(string $s): string
    {
        if (str_contains($s, 'micromessenger')) {
            return '微信';
        }
        if (self::containsAny($s, ['edg/', 'edga', 'edgios'])) {
            return 'Edge';
        }
        if (str_contains($s, 'opr/') || str_contains($s, 'opera')) {
            return 'Opera';
        }
        if (str_contains($s, 'firefox/') || str_contains($s, 'fxios')) {
            return 'Firefox';
        }
        if (str_contains($s, 'chrome/') || str_contains($s, 'crios')) {
            return 'Chrome';
        }
        if (str_contains($s, 'safari/')) {
            return 'Safari';
        }
        if (str_contains($s, 'msie') || str_contains($s, 'trident/')) {
            return 'IE';
        }

        return '其他';
    }

    /** @param string[] $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function date(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
