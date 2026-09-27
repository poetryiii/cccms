<?php

declare(strict_types=1);

use plugin\cccms\app\logic\LogLogic;
use plugin\cccms\support\LogChain;
use think\facade\Db;

/**
 * P2-13：登录安全分析（失败趋势 / TOP 用户名 / TOP IP / 异地登录）。
 *
 * 用**显式时间窗口**把统计范围收窄到本用例刚写入的几条记录上：
 * 分析接口本身统计的是「窗口内全部登录记录」，若依赖默认 7 天窗口，
 * 结果会被库里既有的真实数据影响而变得不可断言。
 *
 * 需要真实 MySQL（登录记录存在 sys_log）。
 */
return static function (): void {
    suite('登录安全分析（P2-13）');

    $marker = '__itest__login';

    $cleanup = static function () use ($marker): void {
        Db::name('log')->where('username', 'like', $marker . '%')->delete();
        LogChain::reanchor();
    };

    /** 写一条登录记录（path 固定 /auth/login，与 recordLogin 的口径一致） */
    $login = static function (string $username, string $ip, bool $success) use ($marker): void {
        LogChain::write([
            'user_id'     => 0,
            'username'    => $marker . '_' . $username,
            'method'      => 'POST',
            'path'        => '/auth/login',
            'node'        => '',
            'title'       => '登录',
            'status'      => $success ? 1 : 0,
            'message'     => $success ? '' : '密码错误',
            'trace_id'    => '',
            'ip'          => $ip,
            'ua'          => 'phpunit',
            'params'      => '{}',
            'result'      => '',
            'status_code' => $success ? 200 : 400,
            'cost'        => 1,
        ]);
    };

    test('聚合口径：失败趋势 / TOP 用户名 / TOP IP / 异地登录', function () use ($login, $cleanup, $marker): void {
        try {
            $start = date('Y-m-d H:i:s', time() - 60);
            $end   = date('Y-m-d H:i:s', time() + 60);

            // 撞库特征：同一账号被反复试错 + 同一 IP 反复失败
            for ($i = 0; $i < 12; $i++) {
                $login('victim', '198.51.100.7', false);
            }
            // 异地登录特征：同一账号从两个不同 IP 成功登录
            $login('roamer', '198.51.100.20', true);
            $login('roamer', '203.0.113.99', true);
            // 正常账号
            $login('normal', '198.51.100.30', true);

            $result = LogLogic::loginAnalysis(['start' => $start, 'end' => $end]);

            same(15, $result['summary']['total'], '窗口内应恰好统计到 15 条登录记录');
            same(12, $result['summary']['failed'], '失败 12 条');
            same(3, $result['summary']['success'], '成功 3 条');
            same(80.0, $result['summary']['fail_rate'], '失败率 = 12 / 15');

            // TOP 用户名：失败最多的那个账号必须排第一
            $topUser = $result['top_users'][0] ?? [];
            same($marker . '_victim', (string)($topUser['username'] ?? ''), 'TOP 用户名第一应是被爆破的账号');
            same(12, (int)($topUser['count'] ?? 0), '该账号失败次数应为 12');

            // TOP IP：失败来源
            $topIp = $result['top_ips'][0] ?? [];
            same('198.51.100.7', (string)($topIp['ip'] ?? ''), 'TOP IP 第一应是失败来源');
            same(12, (int)($topIp['count'] ?? 0), '该 IP 失败次数应为 12');

            // 异地登录：只统计**成功**登录之间的 IP 变化
            $changes = array_values(array_filter(
                $result['ip_changes'],
                static fn (array $row): bool => $row['username'] === $marker . '_roamer'
            ));
            same(1, count($changes), 'roamer 应被识别出 1 次异地登录');
            same('198.51.100.20', $changes[0]['from_ip'], '上一次成功登录的 IP');
            same('203.0.113.99', $changes[0]['to_ip'], '本次成功登录的 IP');
        } finally {
            $cleanup();
        }
    }, true);

    test('趋势按小时或按天分桶，且补齐空桶（图上不会出现断点）', function () use ($login, $cleanup): void {
        try {
            $start = date('Y-m-d H:i:s', time() - 7200);
            $end   = date('Y-m-d H:i:s', time() + 60);

            $login('trend', '203.0.113.5', false);
            $login('trend', '203.0.113.5', true);

            $result = LogLogic::loginAnalysis(['start' => $start, 'end' => $end]);

            same('hour', $result['range']['granularity'], '两小时窗口应按小时聚合');
            ok(count($result['trend']) >= 3, '2 小时窗口 + 补齐空桶后至少 3 个桶');
            ok(
                array_sum(array_column($result['trend'], 'failed')) >= 1
                && array_sum(array_column($result['trend'], 'success')) >= 1,
                '趋势里应同时出现成功与失败'
            );

            // 桶按时间升序，且首桶不晚于窗口起点所在小时
            $buckets = array_column($result['trend'], 'bucket');
            $sorted  = $buckets;
            sort($sorted);
            same($sorted, $buckets, '趋势桶必须按时间升序（图表的 X 轴依赖这个顺序）');

            // 长窗口切换成「按天」
            $week = LogLogic::loginAnalysis(['days' => 7]);
            same('day', $week['range']['granularity'], '7 天窗口应按天聚合');
        } finally {
            $cleanup();
        }
    }, true);

    test('窗口参数：days 有上下界，显式 start / end 优先', function (): void {
        $default = LogLogic::loginAnalysis([]);
        ok($default['range']['start'] !== '' && $default['range']['end'] !== '', '默认窗口应给出起止');

        // days 超上限被收敛到 90 天：起点不会早于 90 天前
        $huge = LogLogic::loginAnalysis(['days' => 100000]);
        ok(
            strtotime($huge['range']['start']) >= strtotime(date('Y-m-d H:i:s', strtotime('-90 days'))) - 86400,
            'days 应被收敛到 90 天上限'
        );

        // 显式区间优先级最高
        $explicit = LogLogic::loginAnalysis(['days' => 30, 'start' => '2026-01-01', 'end' => '2026-01-02']);
        same('2026-01-01 00:00:00', $explicit['range']['start'], '只给到日期的起止应按天补边界');
        same('2026-01-02 23:59:59', $explicit['range']['end'], '结束日期应覆盖当天全天');
    }, true);

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
