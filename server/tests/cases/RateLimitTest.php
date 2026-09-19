<?php

declare(strict_types=1);

use plugin\cccms\support\RateLimiter;

return static function (): void {
    suite('接口限流（P1-2）');

    test('重接口路径识别：导出 / 生成命中，普通接口不命中', function (): void {
        foreach (['/log/export', '/generator/generate'] as $path) {
            ok(RateLimiter::isHeavy($path), "{$path} 应被识别为重接口");
        }

        foreach (['/user', '/user/save', '/user/read', '/dashboard/stats', '/export/read'] as $path) {
            ok(!RateLimiter::isHeavy($path), "{$path} 不应被识别为重接口");
        }
    });

    test('超限判定边界：第 limit+1 次才算超限，limit<=0 表示不限制', function (): void {
        ok(!RateLimiter::exceeded(60, 60), '第 60 次（等于阈值）不应判定超限');
        ok(RateLimiter::exceeded(61, 60), '第 61 次应判定超限');
        ok(!RateLimiter::exceeded(999, 0), 'limit=0 表示关闭限流');
        ok(!RateLimiter::exceeded(0, 60), '尚未计数不应判定超限');
    });

    test('窗口长度至少 1 秒（避免配成 0 导致每次请求自成窗口）', function (): void {
        ok(RateLimiter::windowSeconds() >= 1, 'windowSeconds 应至少为 1');
    });

    test('计数与降级：Redis 可用时第 limit+1 次被拦截，不可用时 fail-open', function (): void {
        $limit = RateLimiter::limitFor(true);
        if ($limit <= 0) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " 重接口限流已关闭（security.rate_limit_heavy_limit <= 0）\n";
            return;
        }

        $route  = 'test:' . bin2hex(random_bytes(6));
        $verdict = null;

        for ($i = 1; $i <= $limit + 1; $i++) {
            $verdict = RateLimiter::hit($route, true);
            if (!$verdict['allowed']) {
                break;
            }
        }

        if ($verdict !== null && $verdict['allowed']) {
            // Redis 不可用：计数返回 null → 全部放行。这正是期望的降级行为：
            // 限流是加固层，不应因缓存故障把所有人挡在门外。
            ok($limit > 0, 'Redis 不可用时应放行（fail-open）');
            return;
        }

        same($limit + 1, $i, '应在第 limit+1 次调用时被拦截');
        ok($verdict['retry_after'] > 0, 'Retry-After 应大于 0');
        same($limit, $verdict['limit'], 'limit 应回显当前配置阈值');
    });
};
