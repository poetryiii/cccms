<?php

declare(strict_types=1);

use plugin\cccms\support\SysConfig;
use support\Redis;

/**
 * R-08：SysConfig 缓存防击穿。
 *
 * 需要真实 MySQL（读配置表）+ Redis（锁与缓存）才执行；两者任一不可用时跳过，
 * 与 tests/run.php 的「本地无库自动跳过」约定一致。
 */
return static function (): void {
    suite('SysConfig 缓存防击穿（R-08）');

    $redisAvailable = static function (): bool {
        try {
            Redis::get('cccms:test:alive');
            return true;
        } catch (Throwable) {
            return false;
        }
    };

    $lockKey  = 'cccms:config:lock';
    $cacheKey = 'cccms:config:all';

    test('正常路径：回填后写入缓存并释放锁', function () use ($redisAvailable, $lockKey, $cacheKey): void {
        if (!$redisAvailable()) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " Redis 不可用\n";
            return;
        }

        SysConfig::flush();
        Redis::del($lockKey);

        $map = SysConfig::all();
        ok($map !== [], '配置表应能读出内容（需要已初始化的库）');

        ok(Redis::get($cacheKey) !== null, '回填后应写入整表缓存');
        same(null, Redis::get($lockKey), '回填结束后应释放回填锁');
    }, true);

    test('抢不到锁时 fail-open：仍能拿到配置，且不释放他人的锁', function () use ($redisAvailable, $lockKey, $cacheKey): void {
        if (!$redisAvailable()) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " Redis 不可用\n";
            return;
        }

        SysConfig::flush();
        // 模拟「另一个请求正在回填」：外部先占住锁
        Redis::set($lockKey, '1', 'EX', 5, 'NX');

        $map = SysConfig::all();
        ok($map !== [], '拿不到锁时应降级直读库，而不是返回空配置');

        same('1', (string)Redis::get($lockKey), '不应释放别人持有的回填锁（只有在 finally 中释放自己拿到的锁）');

        // 清理，避免影响后续用例
        Redis::del($lockKey);
        SysConfig::flush();
    }, true);
};
