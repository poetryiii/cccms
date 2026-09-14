<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use think\facade\Db;
use Throwable;

/** 定时任务执行器（调度进程与「立即执行」共用）。 */
final class CrontabRunner
{
    /**
     * @param array<string,mixed> $task
     * @return array{status:int,output:string,cost:int}
     */
    public static function run(array $task): array
    {
        $target = trim((string)$task['target']);
        $start  = microtime(true);
        $status = 1;

        if (!class_exists($target) || !is_subclass_of($target, CrontabTask::class)) {
            $status = 0;
            $output = '目标非法：必须是在代码中实现 CrontabTask 的类（' . $target . '）';
        } else {
            try {
                $params = $task['params'] ?? [];
                if (is_string($params)) {
                    $params = json_decode($params, true) ?: [];
                }
                /** @var CrontabTask $instance */
                $instance = new $target();
                $output = $instance->run(is_array($params) ? $params : []);
            } catch (Throwable $e) {
                $status = 0;
                $output = $e->getMessage();
            }
        }

        return [
            'status' => $status,
            'output' => (string)$output,
            'cost'   => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * 记录执行日志并更新任务的上次 / 下次执行时间。
     *
     * @param array<string,mixed>          $task
     * @param array{status:int,output:string,cost:int} $result
     */
    public static function writeLog(array $task, array $result, int $now): void
    {
        try {
            Db::name('crontab_log')->insert([
                'crontab_id' => (int)($task['id'] ?? 0),
                'name'       => (string)($task['name'] ?? ''),
                'status'     => (int)$result['status'],
                'output'     => mb_substr($result['output'], 0, 2000),
                'cost'       => (int)$result['cost'],
                'run_time'   => date('Y-m-d H:i:s', $now),
            ]);

            if (!empty($task['id'])) {
                $next = CronMatcher::nextRunTime((string)$task['expression'], $now);
                Db::name('crontab')->where('id', (int)$task['id'])->update([
                    'last_run_time' => date('Y-m-d H:i:s', $now),
                    'next_run_time' => $next ? date('Y-m-d H:i:s', $next) : null,
                ]);
            }
        } catch (Throwable $e) {
            // 日志写入失败不影响任务本身
            Log::error('crontab: write log failed: ' . $e->getMessage());
        }
    }
}
