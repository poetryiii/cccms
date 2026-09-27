<?php

declare(strict_types=1);

use plugin\cccms\support\ApiException;
use plugin\cccms\support\LoginThrottle;
use plugin\cccms\support\SysConfig;
use support\Redis;
use think\facade\Db;

/**
 * R-05：登录失败锁定的**双维度**常驻用例。
 *
 * 需要真实 MySQL（读 sys_config 阈值）与 Redis（计数 / TTL），本地缺任一即跳过；
 * CI 设了 `CCCMS_TEST_REQUIRE_SERVICES=1`，跳过会直接判失败（见 tests/run.php）。
 *
 * 用例重点在**「IP 维度超限只限流该 IP，不锁账号」**这条最容易写错、
 * 也最容易被回调改成「一律锁账号」的语义。
 */
return static function (): void {
    suite('登录失败锁定（双维度）');

    /** 用例内统一用带前缀的假身份，避免与真实数据 / 其它用例互相污染 */
    $userA = '__itest__throttle_a';
    $userB = '__itest__throttle_b';
    $ipA   = '203.0.113.10';
    $ipB   = '203.0.113.20';

    $redisReady = static function (): bool {
        try {
            Redis::get('cccms:test:alive');

            return true;
        } catch (Throwable) {
            return false;
        }
    };

    $userKey = static fn (string $username): string => 'cccms:login_fail:user:' . strtolower($username);
    $ipKey   = static fn (string $ip): string => 'cccms:login_fail:ip:' . $ip;

    /** 写阈值配置并让缓存立即失效（`SysConfig` 整表缓存在 Redis，必须 flush） */
    $setConfig = static function (string $name, string $value): void {
        $exists = Db::name('config')->where('name', $name)->find();
        if ($exists) {
            Db::name('config')->where('name', $name)->update(['value' => $value, 'status' => 1]);
        } else {
            Db::name('config')->insert([
                'name' => $name, 'title' => 'itest', 'type' => 'input-number', 'value' => $value,
                'group' => '安全', 'sort' => 99, 'status' => 1, 'remark' => 'integration test',
                'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        SysConfig::flush();
    };

    /** 恢复：删掉用例插入的配置行、还原被改过的值、清计数 key */
    $cleanup = static function () use ($setConfig, $userKey, $ipKey, $userA, $userB, $ipA, $ipB): void {
        try {
            Redis::del($userKey($userA), $userKey($userB), $ipKey($ipA), $ipKey($ipB));
        } catch (Throwable) {
            // 忽略
        }
        $setConfig('security.login_fail_limit', '5');
        $setConfig('security.login_fail_ip_limit', '20');
        $setConfig('security.login_fail_window', '15');
        Db::name('config')->where('remark', 'integration test')->delete();
        SysConfig::flush();
    };

    $reset = static function () use ($redisReady, $userKey, $ipKey, $userA, $userB, $ipA, $ipB): bool {
        if (!$redisReady()) {
            return false;
        }
        Redis::del($userKey($userA), $userKey($userB), $ipKey($ipA), $ipKey($ipB));

        return true;
    };

    test('未达阈值不锁定（失败计数存在 ≠ 已锁定）', function () use ($reset, $setConfig, $userA, $ipA): void {
        if (!$reset()) {
            skipNeedsService('未达阈值不锁定', 'Redis 不可用');
            return;
        }
        $setConfig('security.login_fail_limit', '3');
        $setConfig('security.login_fail_ip_limit', '10');

        LoginThrottle::recordFailure($userA, $ipA);
        LoginThrottle::recordFailure($userA, $ipA);

        same(0, LoginThrottle::lockedSeconds($userA, $ipA), '失败 2 次（阈值 3）不该锁定');
        LoginThrottle::assertNotLocked($userA, $ipA);   // 不抛即通过
    }, true);

    test('账号维度达阈值后锁定，并抛 429', function () use ($reset, $setConfig, $userA, $ipA): void {
        if (!$reset()) {
            skipNeedsService('账号维度锁定', 'Redis 不可用');
            return;
        }
        $setConfig('security.login_fail_limit', '3');
        $setConfig('security.login_fail_ip_limit', '10');
        $setConfig('security.login_fail_window', '1');

        for ($i = 0; $i < 3; $i++) {
            LoginThrottle::recordFailure($userA, $ipA);
        }

        ok(LoginThrottle::lockedSeconds($userA, $ipA) > 0, '达到阈值应处于锁定状态');
        same(0, LoginThrottle::remainingAttempts($userA), '锁定时剩余尝试次数应为 0');

        try {
            LoginThrottle::assertNotLocked($userA, $ipA);
            fail('锁定时 assertNotLocked 应抛异常');
        } catch (ApiException $e) {
            same(429, $e->getCode(), '锁定应返回 429');
        }
    }, true);

    test('IP 维度达阈值只限流该 IP，不锁账号（避免误伤同出口 IP 的同事）', function () use ($reset, $setConfig, $userA, $userB, $ipA, $ipB): void {
        if (!$reset()) {
            skipNeedsService('IP 维度限流', 'Redis 不可用');
            return;
        }
        // 账号阈值放到很高，确保下面命中的是 IP 维度而不是账号维度
        $setConfig('security.login_fail_limit', '99');
        $setConfig('security.login_fail_ip_limit', '2');
        $setConfig('security.login_fail_window', '1');

        LoginThrottle::recordFailure($userA, $ipA);
        LoginThrottle::recordFailure($userA, $ipA);

        // 同一 IP：被限流
        ok(LoginThrottle::lockedSeconds($userA, $ipA) > 0, '同 IP 连续失败达阈值应被限流');
        // 同一账号换 IP：不受影响 —— 说明限流只挂在 IP 维度上
        same(0, LoginThrottle::lockedSeconds($userA, $ipB), '同一账号换 IP 不该被锁');
        // 其他账号 + 其他 IP：完全不受影响
        same(0, LoginThrottle::lockedSeconds($userB, $ipB), '其他账号其他 IP 不该受牵连');
    }, true);

    test('登录成功后 clear() 同时复位账号与 IP 两个维度', function () use ($reset, $setConfig, $userA, $ipA): void {
        if (!$reset()) {
            skipNeedsService('clear 复位', 'Redis 不可用');
            return;
        }
        $setConfig('security.login_fail_limit', '3');
        $setConfig('security.login_fail_ip_limit', '3');

        LoginThrottle::recordFailure($userA, $ipA);
        LoginThrottle::recordFailure($userA, $ipA);
        same(1, LoginThrottle::remainingAttempts($userA), '失败 2 次（阈值 3）剩余 1 次');

        LoginThrottle::clear($userA, $ipA);

        same(0, LoginThrottle::lockedSeconds($userA, $ipA), 'clear 后应完全复位');
        same(3, LoginThrottle::remainingAttempts($userA), 'clear 后剩余次数回到阈值');
    }, true);

    test('阈值为 0 表示关闭该维度（不计数、不锁定）', function () use ($reset, $setConfig, $userA, $ipA): void {
        if (!$reset()) {
            skipNeedsService('阈值 0 关闭', 'Redis 不可用');
            return;
        }
        $setConfig('security.login_fail_limit', '0');
        $setConfig('security.login_fail_ip_limit', '0');

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($userA, $ipA);
        }

        same(0, LoginThrottle::lockedSeconds($userA, $ipA), '阈值 0 时不该锁定');
        same(PHP_INT_MAX, LoginThrottle::remainingAttempts($userA), '账号维度关闭时剩余次数为无限');
    }, true);

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
