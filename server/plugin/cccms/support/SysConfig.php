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
 * 读取异常一律降级（返回默认值），不能让「配置」把业务打挂。
 */
final class SysConfig
{
    private const CACHE_KEY = 'cccms:config:all';
    private const CACHE_TTL = 300;

    /** @return array<string,string> 全部启用中的配置（name => value） */
    public static function all(): array
    {
        $cached = self::fromCache();
        if ($cached !== null) {
            return $cached;
        }

        try {
            $rows = Db::name('config')->where('status', 1)->column('value', 'name');
            $map  = array_map(static fn ($value): string => (string)$value, (array)$rows);
        } catch (Throwable) {
            // 库不可用时不写缓存，避免把故障固化 5 分钟
            return [];
        }

        self::toCache($map);
        return $map;
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
