<?php

declare(strict_types=1);

use plugin\cccms\support\CronMatcher;

return static function (): void {
    suite('CronMatcher（六段表达式）');

    test('整点匹配与不匹配', function (): void {
        $ts = mktime(3, 0, 0, 6, 15, 2026);
        ok(CronMatcher::isDue('0 0 3 * * *', (int)$ts), '03:00:00 应命中');
        ok(!CronMatcher::isDue('0 0 4 * * *', (int)$ts), '04:00:00 不该命中');
    });

    test('段数不对一律不匹配', function (): void {
        ok(!CronMatcher::isDue('0 3 * * *', time()), '五段（Linux 风格）应被拒绝');
        ok(!CronMatcher::isDue('', time()), '空表达式应被拒绝');
        ok(!CronMatcher::isDue('0 0 3 * * * *', time()), '七段应被拒绝');
    });

    test('步长、列表、区间', function (): void {
        $ts = mktime(10, 30, 0, 6, 15, 2026);
        ok(CronMatcher::isDue('0 */10 * * * *', (int)$ts), '分钟 0/10/20… 应命中 30');
        ok(CronMatcher::isDue('0 0,30 * * * *', (int)$ts), '列表 0,30 应命中 30');
        ok(!CronMatcher::isDue('0 1,31 * * * *', (int)$ts), '列表 1,31 不该命中 30');
        ok(CronMatcher::isDue('0 25-35 * * * *', (int)$ts), '区间 25-35 应命中 30');
    });

    test('日与周同时限定按 AND（与 Linux 一致，不是 OR）', function (): void {
        // 找一个「是周一但不是 20 号」的时刻，避免依赖具体日历
        $ts = null;
        for ($day = 1; $day <= 14; $day++) {
            $candidate = (int)mktime(0, 0, 0, 6, $day, 2026);
            if ((int)date('w', $candidate) === 1 && (int)date('j', $candidate) !== 20) {
                $ts = $candidate;
                break;
            }
        }
        ok($ts !== null, '前提：2026 年 6 月上旬应存在一个周一');

        ok(CronMatcher::isDue('0 0 0 * * 1', $ts), '只限周一时应命中');
        ok(!CronMatcher::isDue('0 0 0 20 * 1', $ts), '同时限定 20 号与周一时，按 AND 不该命中');
    });

    test('nextRunTime 返回准确的下一时刻', function (): void {
        $from = (int)mktime(3, 0, 1, 6, 15, 2026);
        $next = CronMatcher::nextRunTime('0 0 4 * * *', $from);
        same((int)mktime(4, 0, 0, 6, 15, 2026), $next);
    });

    test('nextRunTime 对非法表达式返回 null', function (): void {
        same(null, CronMatcher::nextRunTime('not a cron', time()));
        same(null, CronMatcher::nextRunTime('0 0 3 * *', time()));
    });

    test('markAndCheckHit 同一秒只命中一次', function (): void {
        $ts  = 1800000000;
        $key = 'unit-test-task';
        same(false, CronMatcher::markAndCheckHit($key, $ts), '首次应返回「未执行过」');
        same(true, CronMatcher::markAndCheckHit($key, $ts), '同秒第二次应返回「已执行过」');
        same(false, CronMatcher::markAndCheckHit($key, $ts + 1), '下一秒应重新放行');
    });
};
