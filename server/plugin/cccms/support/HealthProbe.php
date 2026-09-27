<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * 依赖探针（`GET /healthz` 的数据源）。
 *
 * 与 `GET /ping` 的分工：
 *   - `/ping` 是**存活探针**：只证明「进程还能收请求」，不碰任何外部依赖，
 *     因此它是容器 `HEALTHCHECK` 的旧口径（`Dockerfile` 曾探它）；
 *   - `/healthz` 是**就绪探针**：把 MySQL / Redis / 磁盘 / 内存逐项查一遍，
 *     任一项故障返回 503 —— 编排系统据此把实例摘出流量，而不是重启它。
 *
 * 设计取舍：
 *   - **逐项独立**，一项失败不影响其它项的结论（故障排查时最有价值的是「哪几项坏了」，
 *     而不是「有一项坏了」）；
 *   - 返回**结构化明细**而非一句话，调用方（CI 冒烟、负载均衡、运维脚本）可以按项判断；
 *   - 探测本身**绝不抛错**：这里报的是「依赖状态」，探测代码自己崩了就变成了假故障。
 */
final class HealthProbe
{
    /** 磁盘剩余低于该比例视为不健康（%） */
    private const DISK_FREE_PERCENT_MIN = 5.0;

    /** 进程内存占用达到 `memory_limit` 的该比例视为不健康（%） */
    private const MEMORY_USED_PERCENT_MAX = 90.0;

    /**
     * 执行全部探测。
     *
     * @return array{
     *   ok: bool,
     *   time: string,
     *   checks: array<string, array{ok: bool, detail: string, value?: float|null}>
     * }
     */
    public static function run(): array
    {
        $checks = [
            'mysql'  => self::mysql(),
            'redis'  => self::redis(),
            'disk'   => self::disk(),
            'memory' => self::memory(),
        ];

        $ok = true;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'time' => date('Y-m-d H:i:s'), 'checks' => $checks];
    }

    /** @return array{ok:bool,detail:string} */
    private static function mysql(): array
    {
        try {
            Db::query('SELECT 1');

            return ['ok' => true, 'detail' => 'ok'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'mysql unavailable: ' . $e->getMessage()];
        }
    }

    /**
     * Redis 探活用「写 → 读 → 删」而不是只发 PING：
     * PING 只能证明连接在，读写权限 / 内存耗尽（`OOM command not allowed`）时仍会 PING 通过，
     * 而业务真正依赖的是读写。
     */
    private static function redis(): array
    {
        $key = 'cccms:healthz:' . bin2hex(random_bytes(4));

        try {
            Redis::setex($key, 5, '1');
            $value = Redis::get($key);
            Redis::del($key);

            return $value === '1'
                ? ['ok' => true, 'detail' => 'ok']
                : ['ok' => false, 'detail' => 'redis read-back mismatch'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'redis unavailable: ' . $e->getMessage()];
        }
    }

    /** @return array{ok:bool,detail:string,value:float|null} */
    private static function disk(): array
    {
        $stats = self::diskStats();

        if ($stats['free'] === null || $stats['total'] === null) {
            // 拿不到容量信息（部分容器文件系统如此）时**不误报故障**，只说明没有数据
            return ['ok' => true, 'detail' => 'unsupported', 'value' => null];
        }

        $percent = round($stats['free'] / $stats['total'] * 100, 2);
        $ok      = $percent >= self::DISK_FREE_PERCENT_MIN;

        return [
            'ok'     => $ok,
            'detail' => sprintf(
                'free %s / %s (%.2f%%)',
                self::bytes($stats['free']),
                self::bytes($stats['total']),
                $percent
            ),
            'value'  => $percent,
        ];
    }

    /** @return array{ok:bool,detail:string,value:float|null} */
    private static function memory(): array
    {
        $stats = self::memoryStats();

        // memory_limit = -1 表示不限制：只报用量，不判故障
        if ($stats['limit'] <= 0) {
            return [
                'ok'     => true,
                'detail' => sprintf('used %s (no limit)', self::bytes((float)$stats['used'])),
                'value'  => null,
            ];
        }

        $percent = round($stats['used'] / $stats['limit'] * 100, 2);
        $ok      = $percent < self::MEMORY_USED_PERCENT_MAX;

        return [
            'ok'     => $ok,
            'detail' => sprintf(
                'used %s / %s (%.2f%%)',
                self::bytes((float)$stats['used']),
                self::bytes((float)$stats['limit']),
                $percent
            ),
            'value'  => $percent,
        ];
    }

    /**
     * 磁盘剩余 / 总量（字节）。
     *
     * 单独暴露给 `/metrics` 复用：探针要的是「百分比是否越线」，
     * 指标要的是「原始字节」，两者共用同一份取数逻辑，避免口径漂移。
     *
     * @return array{free: float|null, total: float|null}
     */
    public static function diskStats(): array
    {
        $path = self::runtimePath();

        try {
            $free  = @disk_free_space($path);
            $total = @disk_total_space($path);
        } catch (Throwable) {
            $free = $total = false;
        }

        return [
            'free'  => is_float($free) ? $free : null,
            'total' => is_float($total) ? $total : null,
        ];
    }

    /** 进程内存用量与 `memory_limit`（字节；limit 为 0 表示不限制） */
    public static function memoryStats(): array
    {
        return [
            'used'  => memory_get_usage(true),
            'limit' => self::parseBytes((string)ini_get('memory_limit')),
        ];
    }

    /** 探磁盘时优先看运行时目录（附件/日志都写在这里），拿不到再退回项目根 */
    private static function runtimePath(): string
    {
        if (function_exists('base_path')) {
            $runtime = base_path() . '/runtime';
            if (is_dir($runtime)) {
                return $runtime;
            }

            return base_path();
        }

        return sys_get_temp_dir();
    }

    /** 人类可读的字节数（探针明细用，不参与阈值判断） */
    public static function bytes(float $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2) . $units[$i];
    }

    /** 解析 `128M` / `1G` / `-1` 这类 php.ini 写法为字节数；无法解析返回 0 */
    public static function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int)$value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
