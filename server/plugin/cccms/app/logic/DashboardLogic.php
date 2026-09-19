<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Crontab;
use plugin\cccms\app\model\DataRule;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\DictType;
use plugin\cccms\app\model\File;
use plugin\cccms\app\model\Menu;
use plugin\cccms\app\model\OperationLog;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\Tenant;
use plugin\cccms\app\model\User;
use plugin\cccms\support\UserContext;

/**
 * 工作台统计。
 *
 * 只返回**聚合数字**（计数、按天趋势、类型分布），不含任何业务明细行，
 * 因此可以安全地对「任意已登录用户」开放（见 DashboardController 的 #[NoAuth]）。
 *
 * 权限只有两层，且都以「当前账号能不能进对应列表页」为准：
 * - **超管**：`UserContext::hasPermission()` 直通，因此拿到全部统计；
 * - **其他账号**：只拿到自己持有权限节点的模块计数，其余键**不下发**，
 *   前端据此不渲染卡片 —— 否则「进不去列表页，却能从工作台读出该模块的数据量」。
 *
 * 数字口径与各自列表页保持一致：全部走模型查询，参与数据权限的表
 * （`user` / `dept` / `file` / `log`）自动按当前用户的范围统计 ——
 * 否则「列表只能看到 3 个附件、工作台却显示 1000」等于绕开列表把总量漏出去。
 */
final class DashboardLogic
{
    /** 趋势统计的天数 */
    private const TREND_DAYS = 7;

    /** 文件类型分布取前 N 个 */
    private const FILE_TYPE_LIMIT = 6;

    /** 统计项 → 「能进对应列表页」所需的权限节点（无权限则不返回该计数） */
    private const CARD_PERMISSIONS = [
        'user'      => 'cccms:user:index',
        'role'      => 'cccms:role:index',
        'dept'      => 'cccms:dept:tree',
        'post'      => 'cccms:post:index',
        'menu'      => 'cccms:menu:tree',
        'file'      => 'cccms:file:index',
        'log'       => 'cccms:log:index',
        'today_log' => 'cccms:log:index',
        'online'    => 'cccms:online:index',
        'dict'      => 'cccms:dict:index',
        'crontab'   => 'cccms:crontab:index',
        'data_rule' => 'cccms:data_rule:index',
        'tenant'    => 'cccms:tenant:index',
    ];

    public static function stats(UserContext $user): array
    {
        $canLog  = $user->hasPermission(self::CARD_PERMISSIONS['log']);
        $canFile = $user->hasPermission(self::CARD_PERMISSIONS['file']);

        return [
            'counts'     => self::counts($user),
            // 图表同样按权限下发：拿不到日志 / 附件列表页的账号，这里给空数据而不是数字
            'log_trend'  => $canLog ? self::logTrend(self::TREND_DAYS) : ['dates' => [], 'values' => []],
            'file_types' => $canFile ? self::fileTypes(self::FILE_TYPE_LIMIT) : [],
        ];
    }

    /** 关键业务对象的数量（多数只统计启用中的），逐项按权限节点下发 */
    private static function counts(UserContext $user): array
    {
        $today = date('Y-m-d');

        $counters = [
            'user'      => static fn (): int => (int)User::where('status', 1)->count(),
            'role'      => static fn (): int => (int)Role::where('status', 1)->count(),
            'dept'      => static fn (): int => (int)Dept::where('status', 1)->count(),
            'post'      => static fn (): int => (int)Post::where('status', 1)->count(),
            'menu'      => static fn (): int => (int)Menu::where('type', 2)->count(),
            'file'      => static fn (): int => File::newScopedQuery()->count(),
            'log'       => static fn (): int => OperationLog::newScopedQuery()->count(),
            'today_log' => static fn (): int => OperationLog::newScopedQuery()
                ->whereBetween('create_time', [$today . ' 00:00:00', $today . ' 23:59:59'])
                ->count(),
            'online'    => static fn (): int => OnlineLogic::count(),
            'dict'      => static fn (): int => (int)DictType::where('status', 1)->count(),
            'crontab'   => static fn (): int => (int)Crontab::where('status', 1)->count(),
            'data_rule' => static fn (): int => (int)DataRule::count(),
            'tenant'    => static fn (): int => (int)Tenant::count(),
        ];

        $counts = [];
        foreach ($counters as $key => $counter) {
            if ($user->hasPermission(self::CARD_PERMISSIONS[$key])) {
                $counts[$key] = self::safe($counter);
            }
        }

        // 我的未读消息：阅读侧对「登录即可」，与权限节点无关
        $counts['notice_unread'] = self::safe(static fn (): int => NoticeLogic::unreadCount($user->id));

        return $counts;
    }

    /**
     * 容错取值：在线用户数走 Redis，抖动或未启用时退化为 0。
     *
     * 工作台是登录后的默认落地页，不能因为一个附属统计项取不到就整体 500。
     */
    private static function safe(callable $counter): int
    {
        try {
            return (int)$counter();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 最近 N 天的操作日志数量。
     *
     * 用逐天 count 而不是 GROUP BY：日志表通常不大，且能保证「没有数据的日期」也补 0，
     * 前端折线图不会出现断点。
     */
    private static function logTrend(int $days): array
    {
        $dates  = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day    = date('Y-m-d', strtotime("-{$i} day"));
            $dates[]  = $day;
            $values[] = (int)OperationLog::newScopedQuery()
                ->whereBetween('create_time', [$day . ' 00:00:00', $day . ' 23:59:59'])
                ->count();
        }

        return ['dates' => $dates, 'values' => $values];
    }

    /** 附件扩展名分布（饼图用） */
    private static function fileTypes(int $limit): array
    {
        $rows = File::newScopedQuery()
            ->field('ext, COUNT(*) AS num')
            ->group('ext')
            ->order('num', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(
            static fn (array $row): array => [
                'name'  => (string)($row['ext'] ?: '其他'),
                'value' => (int)$row['num'],
            ],
            $rows
        );
    }
}
