<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 列表筛选入参解析。
 *
 * 列头筛选把多选值按逗号拼接下发（如 `status=1,0`），单选就是普通值（`status=1`）；
 * 这里把两种写法统一归一成数组，避免每个列表 Logic 各写一遍 explode。
 */
final class FilterInput
{
    /**
     * 拆成去重后的字符串数组；空值 / 空串 / 纯逗号一律返回空数组。
     *
     * @return string[]
     */
    public static function values(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif ($raw === null || $raw === '') {
            return [];
        } else {
            $parts = explode(',', (string)$raw);
        }

        $out = [];
        foreach ($parts as $part) {
            $value = trim((string)$part);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * 归一成整型数组（用于 id / status 这类数值列）。
     *
     * @return int[]
     */
    public static function ints(mixed $raw): array
    {
        return array_map('intval', self::values($raw));
    }

    /** 是否传了有效的筛选值 */
    public static function has(mixed $raw): bool
    {
        return self::values($raw) !== [];
    }

    /**
     * 只保留白名单内的键（防 Mass Assignment）。
     *
     * 写接口拿到的 `$request->post()` 是**整数组**：可写字段集合原本是**隐式**的 ——
     * 靠模型声明约束、靠 `ScopedQuery` 在写库前剔除 `hidden` / `readonly` / `mask` / `encrypt`。
     * 这套设计自洽，但「哪些普通业务字段可写」没人显式声明，新增字段时容易漏判；
     * 反过来，客户端多塞一个恰好是库列的键（如 `tenant_id` / `create_by` / `dept_id`），
     * 只要模型没声明为只读就会被写进去。
     *
     * 因此每个写方法入口都要显式声明「可写字段」，白名单外的键一律丢弃。
     * 用 `array_intersect_key` 而不是遍历赋值，保证**顺序与类型原样保留**。
     *
     * @param array<string,mixed> $raw
     * @param string[]            $allow
     * @return array<string,mixed>
     */
    public static function only(array $raw, array $allow): array
    {
        return array_intersect_key($raw, array_flip($allow));
    }

    /**
     * 归一化时间范围（列头时间筛选下发 `start` / `end`）。
     *
     * 值可能是纯日期（`Y-m-d`），也可能带时分秒（`Y-m-d H:i:s`，选择器统一带时间）。
     * 这里统一补全成可直接比较的 `Y-m-d H:i:s`：只给到日期的按「起始 00:00:00 /
     * 结束 23:59:59」补边界，保证按天筛选包含当天全天；带时分秒的原样保留，
     * 让筛选可以精确到秒。调用方拿到的就是最终值，不要再自己拼时间后缀。
     *
     * 解析不出来的一律当作「未筛选」，避免脏值进到时间比较里。
     *
     * @return array{0:string,1:string} 起止时间，未筛选时为空串
     */
    public static function range(mixed $start, mixed $end): array
    {
        return [self::bound($start, false), self::bound($end, true)];
    }

    /** 归一化单个边界；`$isEnd` 决定纯日期时补 23:59:59 还是 00:00:00 */
    private static function bound(mixed $raw, bool $isEnd): string
    {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            return '';
        }

        $parsed = date_create($value);
        if ($parsed === false) {
            return '';
        }

        // 未携带时分秒：按天补边界，保证「筛选某天」覆盖该天全天
        if (preg_match('/\d{1,2}:\d{2}/', $value) !== 1) {
            return $parsed->format('Y-m-d') . ($isEnd ? ' 23:59:59' : ' 00:00:00');
        }

        return $parsed->format('Y-m-d H:i:s');
    }
}
