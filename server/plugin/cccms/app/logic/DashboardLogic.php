<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/**
 * 工作台统计。
 *
 * 只返回**聚合数字**（计数、按天趋势、类型分布），不含任何业务明细行，
 * 因此可以安全地对「任意已登录用户」开放（见 DashboardController 的 #[NoAuth]）。
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
            'user'      => (int)SoftDelete::apply(Db::name('user'))->where('status', 1)->count(),
            'role'      => (int)SoftDelete::apply(Db::name('role'))->where('status', 1)->count(),
            'dept'      => (int)SoftDelete::apply(Db::name('dept'))->where('status', 1)->count(),
            'post'      => (int)SoftDelete::apply(Db::name('post'))->where('status', 1)->count(),
            'menu'      => (int)Db::name('menu')->where('type', 2)->count(),
            'file'      => (int)SoftDelete::apply(Db::name('file'))->count(),
            'log'       => (int)Db::name('log')->count(),
            'today_log' => (int)Db::name('log')
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
            $values[] = (int)Db::name('log')
                ->whereBetween('create_time', [$day . ' 00:00:00', $day . ' 23:59:59'])
                ->count();
        }

        return ['dates' => $dates, 'values' => $values];
    }

    /** 附件扩展名分布（饼图用） */
    private static function fileTypes(int $limit): array
    {
        $rows = SoftDelete::apply(Db::name('file'))
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
