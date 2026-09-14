<?php

declare(strict_types=1);

namespace plugin\cccms\command\task;

use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\SysConfig;
use think\facade\Db;

/**
 * 清理历史操作日志。
 *
 * 清理天数与开关都来自后台配置：
 *   log.keep_days  —— 保留多少天，<=0 或未配置时不清理
 *   log.auto_clean —— 关闭后本任务直接跳过（含「立即执行」）
 */
class DemoTask implements CrontabTask
{
    public function run(array $params = []): string
    {
        if (!SysConfig::getBool('log.auto_clean', true)) {
            return '已跳过：后台配置「自动清理日志」处于关闭状态（log.auto_clean=0）';
        }

        // 手工执行时允许用参数临时覆盖配置
        $keepDays = (int)($params['keep_days'] ?? SysConfig::getInt('log.keep_days', 30));
        if ($keepDays <= 0) {
            return '已跳过：日志保留天数未配置（log.keep_days<=0）';
        }

        $before  = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));
        $deleted = Db::name('log')->where('create_time', '<', $before)->delete();

        return "已清理 {$deleted} 条 {$keepDays} 天前的操作日志";
    }
}
