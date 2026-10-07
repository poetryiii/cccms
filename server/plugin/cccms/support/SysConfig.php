<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * 系统配置读取（sys_config）。
 *
 * 取数顺序：Redis（跨进程共享）→ MySQL。
 *
 * 这里**刻意不做进程内缓存**：webman 的 worker 是长驻进程，静态属性会跨请求存活，
 * 一旦缓存住就会让「后台改了配置却不生效」，直到 TTL 过期或重启。
 * 本地 Redis 单次 GET 在百微秒级，直接读完全够用，换来的是改配置立即生效。
 *
 * **缓存击穿防护（R-08）**：整表缓存的 key 过期（或 Redis 冷启动）的一瞬间，
 * 并发请求会同时查库回填。虽然配置表很小，但它是**所有业务读取的前置依赖**，
 * 长驻 worker 下每个请求都要走这条链路，因此回填前加一把短锁（SET NX EX）：
 * 只有抢到锁的请求查库并回填，其余请求**短暂等待后二次读缓存**，仍拿不到就直读库兜底
 * （fail-open，绝不把业务阻塞在锁上）。Redis 不可用时与加锁前完全一致地降级读库。
 *
 * 读取异常一律降级（返回默认值），不能让「配置」把业务打挂。
 */
final class SysConfig
{
    private const CACHE_KEY = 'cccms:config:all';
    private const CACHE_TTL = 300;

    /** 回填互斥锁（仅用于防击穿，业务语义上不占任何资源） */
    private const LOCK_KEY = 'cccms:config:lock';
    /** 锁 TTL：比一次回填（一条小表 SELECT）长得多，又能在持锁进程崩溃时自动释放 */
    private const LOCK_TTL = 5;
    /** 抢不到锁时的等待时长（微秒）：等一次回填 + 二次读缓存，之后立即兜底 */
    private const LOCK_WAIT_US = 20000;

    /** @return array<string,string> 全部启用中的配置（name => value） */
    public static function all(): array
    {
        $cached = self::fromCache();
        if ($cached !== null) {
            return $cached;
        }

        $lock = self::acquireLock();

        // Redis 不可用：与加锁前行为一致 —— 直接读库，不写缓存（避免把故障固化 5 分钟）
        if ($lock === null) {
            return self::fromDatabase() ?? [];
        }

        // 锁被别的请求持有：等它回填完再读一次缓存，仍无则直读库兜底（fail-open）
        if ($lock === false) {
            return self::waitForRefill();
        }

        try {
            $map = self::fromDatabase();
            if ($map === null) {
                return [];
            }

            self::toCache($map);
            return $map;
        } finally {
            self::releaseLock();
        }
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        $value = self::all()[$name] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function getString(string $name, string $default = ''): string
    {
        return (string)self::get($name, $default);
    }

    public static function getInt(string $name, int $default = 0): int
    {
        $value = self::get($name, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public static function getBool(string $name, bool $default = false): bool
    {
        $value = (string)self::get($name, $default ? '1' : '0');
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /** 逗号分隔的多值配置（如 upload.ext_allow） */
    public static function getList(string $name): array
    {
        $raw = self::getString($name);
        if ($raw === '') {
            return [];
        }
        $items = array_map(static fn (string $v): string => strtolower(ltrim(trim($v), '.')), explode(',', $raw));
        return array_values(array_filter($items, static fn (string $v): bool => $v !== ''));
    }

    /**
     * 多行文本 → 字符串列表（一行一条，去掉空白与空行）。纯函数，便于单测。
     *
     * 与 getList 的区别：getList 按英文逗号拆（用于扩展名这类单行值），
     * 这里按换行拆（用于白名单这类「一行一条」的路径/地址）。不转小写 ——
     * 大小写归一由消费方决定（如 RateLimiter::matchesWhitelist 自行 strtolower）。
     *
     * @return list<string>
     */
    public static function splitLines(string $raw): array
    {
        $items = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $items = array_map(static fn (string $v): string => trim($v), $items);

        return array_values(array_filter($items, static fn (string $v): bool => $v !== ''));
    }

    /** 多行配置（textarea，一行一条，如 security.rate_limit_whitelist） */
    public static function getLines(string $name): array
    {
        return self::splitLines(self::getString($name));
    }

    /** 敏感配置的密文前缀（type=password 的配置值以 `enc:` + Cipher 密文存储） */
    public const SECRET_PREFIX = 'enc:';

    /**
     * 读取敏感配置（type=password）。
     *
     * 存储约定：`enc:` + Cipher（AES-256-GCM）密文；读取时自动解密。
     * 兼容直接写入的明文（便于手工初始化）。解密失败返回默认值，不抛错。
     */
    public static function getSecret(string $name, string $default = ''): string
    {
        $value = (string)self::get($name, '');
        if ($value === '') {
            return $default;
        }
        if (!str_starts_with($value, self::SECRET_PREFIX)) {
            return $value;
        }

        try {
            return Cipher::decrypt(substr($value, strlen(self::SECRET_PREFIX)));
        } catch (Throwable) {
            return $default;
        }
    }

    /** 保存配置后调用：清掉 Redis 缓存，各进程下一个请求即拿到新值 */
    public static function flush(): void
    {
        try {
            Redis::del(self::CACHE_KEY);
        } catch (Throwable) {
            // Redis 不可用时忽略：靠 TTL 自然过期
        }
    }

    /**
     * 抢回填锁。
     *
     * `SET NX EX` 是原子操作，跨 phpredis / predis 行为一致（与 RateLimiter 用 INCR 的取舍相同，
     * 不引入 Lua 脚本）。返回 true = 抢到；false = 被别人持有；null = Redis 不可用（调用方降级直读库）。
     */
    private static function acquireLock(): ?bool
    {
        try {
            return (bool)Redis::set(self::LOCK_KEY, '1', 'EX', self::LOCK_TTL, 'NX');
        } catch (Throwable) {
            return null;
        }
    }

    /** 释放回填锁；释放失败不影响正确性（靠 LOCK_TTL 自然过期） */
    private static function releaseLock(): void
    {
        try {
            Redis::del(self::LOCK_KEY);
        } catch (Throwable) {
            // 忽略：锁会自然过期
        }
    }

    /**
     * 没抢到锁时的兜底路径：等一小会儿（让持锁者写完缓存）→ 二次读缓存 → 直读库。
     *
     * 兜底直查可能与持锁者重复一次查库，但代价只是一条小表 SELECT，
     * 远小于「所有并发一起查库」，而且**不会长时间阻塞请求**。
     */
    private static function waitForRefill(): array
    {
        usleep(self::LOCK_WAIT_US);

        $cached = self::fromCache();
        if ($cached !== null) {
            return $cached;
        }

        return self::fromDatabase() ?? [];
    }

    /** 直查库取全量启用配置；失败返回 null，由调用方决定是否写缓存 */
    private static function fromDatabase(): ?array
    {
        try {
            $rows = Db::name('config')->where('status', 1)->column('value', 'name');
            return array_map(static fn ($value): string => (string)$value, (array)$rows);
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromCache(): ?array
    {
        try {
            $raw = Redis::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function toCache(array $map): void
    {
        try {
            Redis::setex(self::CACHE_KEY, self::CACHE_TTL, (string)json_encode($map, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // 缓存写失败不影响本次读取
        }
    }
}
