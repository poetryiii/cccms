<?php

declare(strict_types=1);

namespace plugin\cccms\command\task;

use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\ExportTask;
use plugin\cccms\support\SysConfig;

/**
 * 消费异步导出任务（框架内置定时任务）。
 *
 * 高频（默认每 10 秒）跑一次，把待处理的导出任务逐个生成归档文件 ——
 * 导出接口只负责「落一条待处理记录」就返回，不阻塞在线请求。
 *
 * 顺带做归档文件清理：每次 tick 都调 `ExportTask::cleanup()`，
 * 保留期取 `export.keep_minutes`（默认 60 分钟），过期即删并置为「已过期」。
 */
class ExportTaskConsumer implements CrontabTask
{
    public function run(array $params = []): string
    {
        $consumed = ExportTask::consume();
        $cleaned  = ExportTask::cleanup(SysConfig::getInt('export.keep_minutes', ExportTask::DEFAULT_KEEP_MINUTES));

        $parts = [];
        if ($consumed > 0) {
            $parts[] = "已生成 {$consumed} 个导出文件";
        }
        if ($cleaned > 0) {
            $parts[] = "清理 {$cleaned} 个过期导出";
        }

        return $parts === [] ? '没有待处理的导出任务' : implode('；', $parts);
    }
}
