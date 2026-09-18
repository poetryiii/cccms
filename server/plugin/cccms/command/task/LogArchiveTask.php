<?php

declare(strict_types=1);

namespace plugin\cccms\command\task;

use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\LogArchiver;

/**
 * 归档历史日志到对象存储（框架内置定时任务；seed 预置为**停用**，需手动启用）。
 *
 * 与命令 cccms:log-archive 共用 LogArchiver，保证「先存后删」的安全约束一致。
 * 可在任务的「参数」里临时覆盖配置（便于手工试跑）：
 *   {"days":30,"batch":1000,"path":"/auth/login","dry_run":true}
 *
 * 失败直接抛出：调度会写 status=0 并（按任务配置）重试，绝不静默吞掉。
 */
class LogArchiveTask implements CrontabTask
{
    public function run(array $params = []): string
    {
        $r = LogArchiver::archive($params);

        return sprintf(
            '扫描 %d 条、归档 %d 条、上传文件 %d 个、剩余未归档 %d 条%s',
            $r['scanned'],
            $r['archived'],
            $r['files'],
            $r['remaining'],
            $r['dry_run'] ? '（dry-run）' : ''
        );
    }
}
