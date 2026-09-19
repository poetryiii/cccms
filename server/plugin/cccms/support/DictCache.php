<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 字典数据缓存（Redis + 版本号失效）。
 *
 * 为什么需要：`GET /dict/data` 被各业务页下拉框反复拉取，字典是典型的高频只读数据，
 * 而 `DictLogic::dataList()` 原先每次直查库（无任何缓存）。
 *
 * 失效沿用 `PermissionCache` 的**全局版本号**范式：
 *   - 版本号是只增不减的整数（`cccms:dict:ver`），缓存键为 `cccms:dict:d:{版本}:{类型ID}`；
 *   - 任何字典类型 / 字典数据的写操作（含软删、分类变更）调用一次 `bump()`，
 *     版本 +1 → 旧键立刻不再被读取，**无需遍历删除**，也不怕漏删；
 *   - 旧的缓存键靠 TTL 自然过期回收。
 *
 * 只缓存**回收站视图之外**的数据（`trashed = true` 时直查库）：回收站是低频管理动作，
 * 缓存它既无收益，又会和「恢复 / 彻底删除」产生更多失效点。
 *
 * Redis 不可用时全部降级：`version()` 返回 0、`load()` 返回 null、`store()` 无操作，
 * 等价于「不使用缓存」，完全退回原先的实时查库行为。
 */
final class DictCache
{
    private const VER_KEY  = 'cccms:dict:ver';
    private const DATA_KEY = 'cccms:dict:d:';

    /** 兜底 TTL：即使漏了 `bump()`，字典变更最迟 10 分钟后生效 */
    private const TTL = 600;

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

    /** 版本 +1，使所有字典缓存立即失效。字典相关的写操作都要调用它。 */
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
     * 读取某字典类型的数据列表。
     *
     * @return array<int,array<string,mixed>>|null null = 未命中 / 不可用
     */
    public static function load(int $typeId): ?array
    {
        $version = self::version();
        if ($version <= 0 || $typeId <= 0) {
            return null;
        }

        try {
            $raw = Redis::get(self::key($version, $typeId));
        } catch (Throwable) {
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 写入某字典类型的数据列表。
     *
     * @param array<int,array<string,mixed>> $list
     */
    public static function store(int $typeId, array $list): void
    {
        $version = self::version();
        if ($version <= 0 || $typeId <= 0) {
            return;
        }

        try {
            Redis::setex(
                self::key($version, $typeId),
                self::TTL,
                (string)json_encode($list, JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable) {
            // 写失败不影响本次请求
        }
    }

    private static function key(int $version, int $typeId): string
    {
        return self::DATA_KEY . $version . ':' . $typeId;
    }
}