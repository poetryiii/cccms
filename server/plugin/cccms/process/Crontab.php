<?php

declare(strict_types=1);

namespace plugin\cccms\process;

use plugin\cccms\support\CronMatcher;
use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\SoftDelete;
use support\Log;
use think\facade\Db;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 定时任务调度进程。
 *
 * 每秒 tick 一次，做两件事（顺序固定）：
 *   1. **到期的失败重试**：`retry_left > 0 且 retry_at <= now` 的任务立即执行一次，
 *      不受 cron 表达式约束；重试失败会按 `retry_interval` 再排下一次；
 *   2. **正常 cron 调度**：按六段表达式判断到期，`markAndCheckHit` 按秒去重。
 *
 * 同一轮里被重试处理过的任务不再走 cron 调度，避免一次 tick 跑两遍。
 *
 * 重叠保护 / 超时判定在 `CrontabRunner::execute()` 里（基于任务表的运行锁）。
 * 不做「注册式」调度，因此增删改任务无需 reload 进程。
 * 注意：任务在本进程内**串行**执行，某个任务跑很久会顺延后续 tick；
 * 自定义进程仅在 Linux/macOS 下启动（Windows 不支持）。
 */
class Crontab
{
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1, function () {
            $this->tick();
        });
    }

    private function tick(): void
    {
        $now = time();

        try {
            // 已进回收站的任务不再调度
            $tasks = SoftDelete::apply(Db::name('crontab'))->where('status', 1)->select()->toArray();
        } catch (Throwable $e) {
            Log::error('crontab: load tasks failed: ' . $e->getMessage());
            return;
        }

        $handled = [];

        // ---- ① 到期的失败重试 ----
        foreach ($tasks as $task) {
            if (!$this->retryDue($task, $now)) {
                continue;
            }
            $this->dispatch($task, $now, 'retry');
            $handled[(int)$task['id']] = true;
        }

        // ---- ② 正常 cron 调度 ----
        foreach ($tasks as $task) {
            $id = (int)$task['id'];
            if (isset($handled[$id])) {
                continue;
            }
            if (!CronMatcher::isDue((string)$task['expression'], $now)) {
                continue;
            }
            // 同一秒只执行一次
            if (CronMatcher::markAndCheckHit((string)$task['id'], $now)) {
                continue;
            }
            $this->dispatch($task, $now, 'cron');
        }
    }

    /** @param array<string,mixed> $task */
    private function dispatch(array $task, int $now, string $source): void
    {
        $result = CrontabRunner::execute($task, $now, $source);
        CrontabRunner::writeLog($task, $result, $now, $source);
        CrontabRunner::updateRetry($task, $result, $now, $source);
    }

    /** @param array<string,mixed> $task */
    private function retryDue(array $task, int $now): bool
    {
        if ((int)($task['retry_left'] ?? 0) <= 0) {
            return false;
        }

        $at = (string)($task['retry_at'] ?? '');
        if ($at === '') {
            return false;
        }

        $timestamp = strtotime($at);

        return $timestamp !== false && $timestamp <= $now;
    }
}
