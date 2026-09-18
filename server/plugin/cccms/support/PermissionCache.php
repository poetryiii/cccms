<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 角色 / 权限集合缓存（Redis + 版本号失效）。
 *
 * 为什么需要：`CheckLogin` 每个请求都要解析一次用户的角色与权限节点，
 * 角色多的环境下这是固定 3~4 条 SQL。本类把它缓存到 Redis。
 *
 * 缓存失效用**全局版本号**而不是逐用户删除：
 *   - 版本号是一个只增不减的整数（`cccms:auth:ver`），缓存键为
 *     `cccms:auth:ctx:{版本}:{用户ID}`；
 *   - 任何 RBAC 变更（角色、授权节点、菜单、用户角色关系）调用一次 `bump()`，
 *     版本 +1 → 旧键立刻不再被读取，**无需遍历删除**，也不怕漏删；
 *   - 旧的缓存键靠 TTL 自然过期回收。
 *
 * 刻意**只缓存「角色 + 权限」**，用户行本身（状态 / 昵称）仍然每请求实时查库：
 * 否则「禁用 / 删除用户立即失效」这条硬约束就会被缓存破坏。
 *
 * Redis 不可用时全部降级：`version()` 返回 0，`load()` 返回 null、`store()` 无操作 ——
 * 等价于「不使用缓存」，完全退回原先的实时查库行为。
 */
final class PermissionCache
{
    private const VER_KEY   = 'cccms:auth:ver';
    private const CTX_KEY   = 'cccms:auth:ctx:';

    /** 兜底 TTL：即使漏了 `bump()`，权限变更最迟 5 分钟后生效 */
    private const TTL = 300;

    /** 版本号；0 = 不使用缓存（Redis 不可用） */
    public static function version(): int
    {
        try {
            $ver = Redis::get(self::VER_KEY);
        } catch (Throwable) {
            return 0;
        }

        // 键不存在时从 1 开始（不写 Redis，避免读操作产生写）
        return is_numeric($ver) ? (int)$ver : 1;
    }

    /**
     * 版本 +1，使所有权限缓存立即失效。
     *
     * RBAC 相关写操作都要调用它（角色 / 授权节点 / 菜单 / 用户角色关系）。
     */
    public static function bump(): void
    {
        try {
            if (!is_numeric(Redis::get(self::VER_KEY))) {
                // 首次 bump：直接写成 2，保证「之前按版本 1 缓存的条目」也被作废
                Redis::set(self::VER_KEY, '2');
                return;
            }
            Redis::incr(self::VER_KEY);
        } catch (Throwable) {
            // 降级：不动版本号，靠 TTL 兜底
        }
    }

    /**
     * 读取缓存。
     *
     * @return array{roleIds:array<int,int>,roles:array<int,string>,permissions:array<int,string>,superAdmin:bool}|null
     */
    public static function load(int $userId): ?array
    {
        $version = self::version();
        if ($version <= 0 || $userId <= 0) {
            return null;
        }

        try {
            $raw = Redis::get(self::key($version, $userId));
        } catch (Throwable) {
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) && isset($data['roleIds'], $data['permissions']) ? $data : null;
    }

    /**
     * 写入缓存。
     *
     * @param array{roleIds:array<int,int>,roles:array<int,string>,permissions:array<int,string>,superAdmin:bool} $data
     */
    public static function store(int $userId, array $data): void
    {
        $version = self::version();
        if ($version <= 0 || $userId <= 0) {
            return;
        }

        try {
            Redis::setex(
                self::key($version, $userId),
                self::TTL,
                (string)json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable) {
            // 写失败不影响本次请求
        }
    }

    private static function key(int $version, int $userId): string
    {
        return self::CTX_KEY . $version . ':' . $userId;
    }
}
