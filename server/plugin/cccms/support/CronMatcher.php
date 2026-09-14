<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * Cron 表达式匹配（`秒 分 时 日 月 周` 六段，与 webman 的 crontab 一致）。
 *
 * 每段支持：任意（*）、单值（5）、列表（1,3,5）、区间（1-5）、步长（星号/5、0/5、1-30/5）。
 *
 * 两点与 Linux 一致、但容易踩的语义：
 *   - 日与周同时限定时按 **AND** 处理（部分系统是 OR）；
 *   - 不做「月份实际天数」回绕，31 号在 2 月不触发（由 nextRunTime 返回 null 判定视为永不执行）。
 */
final class CronMatcher
{
    /** 六段取值范围：秒 分 时 日 月 周 */
    private const RANGES = [
        [0, 59],
        [0, 59],
        [0, 23],
        [1, 31],
        [1, 12],
        [0, 6],
    ];

    /** @var array<string,int> 已命中的「任务#时刻」，避免同一秒重复执行 */
    private static array $hitCache = [];

    public static function isDue(string $expression, int $timestamp): bool
    {
        $fields = self::fields($expression);
        if ($fields === null) {
            return false;
        }

        $values = [
            (int)date('s', $timestamp),
            (int)date('i', $timestamp),
            (int)date('G', $timestamp),
            (int)date('j', $timestamp),
            (int)date('n', $timestamp),
            (int)date('w', $timestamp),
        ];

        foreach (self::RANGES as $i => [$min, $max]) {
            if (!self::matchField($fields[$i], $values[$i], $min, $max)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 计算下一次执行时间（向前最多扫 366 天，找不到说明一年内不会触发）。
     *
     * 秒级推进；外层按月 / 日 / 时 / 分整块跳跃，不会逐秒空跑。
     */
    public static function nextRunTime(string $expression, int $from): ?int
    {
        $fields = self::fields($expression);
        if ($fields === null) {
            return null;
        }
        [$second, $minute, $hour, $day, $month, $week] = $fields;

        $time  = $from + 1;
        $limit = $from + 366 * 86400;

        while ($time <= $limit) {
            if (!self::matchField($month, (int)date('n', $time), 1, 12)) {
                $time = (int)mktime(0, 0, 0, (int)date('n', $time) + 1, 1, (int)date('Y', $time));
                continue;
            }
            if (!self::matchField($day, (int)date('j', $time), 1, 31)
                || !self::matchField($week, (int)date('w', $time), 0, 6)) {
                $time = (int)mktime(0, 0, 0, (int)date('n', $time), (int)date('j', $time) + 1, (int)date('Y', $time));
                continue;
            }
            if (!self::matchField($hour, (int)date('G', $time), 0, 23)) {
                $time = (int)mktime((int)date('G', $time) + 1, 0, 0, (int)date('n', $time), (int)date('j', $time), (int)date('Y', $time));
                continue;
            }
            if (!self::matchField($minute, (int)date('i', $time), 0, 59)) {
                $time = $time - ($time % 60) + 60;
                continue;
            }
            if (!self::matchField($second, (int)date('s', $time), 0, 59)) {
                $time++;
                continue;
            }

            return $time;
        }

        return null;
    }

    /**
     * 标记并检查「该秒是否已执行过」。
     *
     * 调度进程每秒 tick，表达式又是秒级的，所以按秒去重 ——
     * 少了这层，命中同一秒的任务会被重复执行。
     *
     * @param string $key 任务唯一标识（一般用任务 ID）
     * @return bool true = 该秒已执行过，应跳过
     */
    public static function markAndCheckHit(string $key, int $timestamp): bool
    {
        $slot = $key . '#' . date('YmdHis', $timestamp);
        if (isset(self::$hitCache[$slot])) {
            return true;
        }
        self::$hitCache[$slot] = $timestamp;

        // 清理 2 分钟前的记录，避免常驻进程内存增长
        if (count(self::$hitCache) > 200) {
            $threshold = $timestamp - 120;
            foreach (self::$hitCache as $k => $ts) {
                if ($ts < $threshold) {
                    unset(self::$hitCache[$k]);
                }
            }
        }

        return false;
    }

    /**
     * 拆成 6 段；段数不对返回 null。
     *
     * @return string[]|null
     */
    private static function fields(string $expression): ?array
    {
        $fields = array_values(array_filter(
            preg_split('/\s+/', trim($expression)) ?: [],
            static fn (string $v): bool => $v !== ''
        ));

        return count($fields) === 6 ? $fields : null;
    }

    private static function matchField(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if ($item === '*') {
                return true;
            }

            if (str_contains($item, '/')) {
                [$range, $step] = explode('/', $item, 2);
                $step = (int)$step;
                if ($step <= 0) {
                    continue;
                }
                [$start, $end] = self::range($range, $min, $max);
                if ($value >= $start && $value <= $end && ($value - $start) % $step === 0) {
                    return true;
                }
                continue;
            }

            if (str_contains($item, '-')) {
                [$start, $end] = self::range($item, $min, $max);
                if ($value >= $start && $value <= $end) {
                    return true;
                }
                continue;
            }

            if ((int)$item === $value) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int,1:int} */
    private static function range(string $item, int $min, int $max): array
    {
        if ($item === '*') {
            return [$min, $max];
        }
        if (str_contains($item, '-')) {
            [$a, $b] = explode('-', $item, 2);
            return [(int)$a, (int)$b];
        }
        return [(int)$item, (int)$item];
    }
}
