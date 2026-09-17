<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\File;
use plugin\cccms\app\model\Menu;
use plugin\cccms\app\model\OperationLog;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;

/**
 * 工作台统计。
 *
 * 只返回**聚合数字**（计数、按天趋势、类型分布），不含任何业务明细行，
 * 因此可以安全地对「任意已登录用户」开放（见 DashboardController 的 #[NoAuth]）。
 *
 * 统计口径与各自列表页保持一致：全部走模型查询，参与数据权限的表
 * （`user` / `dept` / `file` / `log`）自动按当前用户的范围统计 ——
 * 否则「列表只能看到 3 个附件、工作台却显示 1000」等于绕开列表把总量漏出去。
 * 声明不参与的表（`role` / `post` / `menu`）不受影响。
 */
final class DashboardLogic
{
    /** 趋势统计的天数 */
    private const TREND_DAYS = 7;

    /** 文件类型分布取前 N 个 */
    private const FILE_TYPE_LIMIT = 6;

    public static function stats(): array
    {
        return [
            'counts'     => self::counts(),
            'log_trend'  => self::logTrend(self::TREND_DAYS),
            'file_types' => self::fileTypes(self::FILE_TYPE_LIMIT),
        ];
    }

    /** 关键业务对象的数量（用户/角色等只统计启用中的） */
    private static function counts(): array
    {
        $today = date('Y-m-d');

        return [
            'user'      => (int)User::where('status', 1)->count(),
            'role'      => (int)Role::where('status', 1)->count(),
            'dept'      => (int)Dept::where('status', 1)->count(),
            'post'      => (int)Post::where('status', 1)->count(),
            'menu'      => (int)Menu::where('type', 2)->count(),
            'file'      => (int)File::newScopedQuery()->count(),
            'log'       => (int)OperationLog::newScopedQuery()->count(),
            'today_log' => (int)OperationLog::newScopedQuery()
                ->whereBetween('create_time', [$today . ' 00:00:00', $today . ' 23:59:59'])
                ->count(),
        ];
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
