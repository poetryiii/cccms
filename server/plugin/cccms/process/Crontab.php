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
 * 每秒 tick 一次，从 sys_crontab 读取启用任务并按 cron 表达式判断是否到期执行。
 * 表达式是 6 段（秒 分 时 日 月 周），要精确到秒触发，所以调度粒度就是一秒；
 * `CronMatcher::markAndCheckHit` 按秒去重，保证同一秒只跑一次。
 *
 * 不做「注册式」调度，因此增删改任务无需 reload 进程。
 * 注意：任务在调度进程内**串行**执行，某个任务跑很久会顺延后续 tick；
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

        foreach ($tasks as $task) {
            $expression = (string)$task['expression'];
            if (!CronMatcher::isDue($expression, $now)) {
                continue;
            }
            // 同一秒只执行一次
            if (CronMatcher::markAndCheckHit((string)$task['id'], $now)) {
                continue;
            }
            CrontabRunner::writeLog($task, CrontabRunner::run($task), $now);
        }
    }
}
