<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\CronMatcher;
use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\SoftDelete;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\facade\Db;

/** 定时任务逻辑。 */
final class CrontabLogic
{
    public static function paginate(array $params): array
    {
        $query = SoftDelete::listQuery('crontab', $params);
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        foreach ($list as &$row) {
            $next = CronMatcher::nextRunTime((string)$row['expression'], time());
            $row['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /** 可作为调度目标的任务类（实现 CrontabTask）。 */
    public static function targets(): array
    {
        $dir = base_path() . '/plugin/cccms/command/task';
        $result = [];
        if (!is_dir($dir)) {
            return $result;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($dir) + 1, -4);
            $class = 'plugin\\cccms\\command\\task\\' . str_replace(DIRECTORY_SEPARATOR, '\\', (string)$rel);
            if (class_exists($class) && is_subclass_of($class, CrontabTask::class)) {
                $short = substr((string)strrchr($class, '\\'), 1);
                $result[] = ['class' => $class, 'label' => $short];
            }
        }
        return $result;
    }

    public static function create(array $data): int
    {
        self::assertValid($data);
        $data['params'] = self::encodeParams($data['params'] ?? []);
        $next = CronMatcher::nextRunTime((string)$data['expression'], time());
        $data['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;

        return (int)Db::name('crontab')->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        self::assertExists($id);
        self::assertValid($data);
        if (array_key_exists('params', $data)) {
            $data['params'] = self::encodeParams($data['params']);
        }
        $next = CronMatcher::nextRunTime((string)$data['expression'], time());
        $data['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;

        Db::name('crontab')->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertExists($id);
        // 软删除：进回收站。执行日志是历史记录，刻意保留（调度进程已按软删过滤，不会继续跑）
        SoftDelete::remove(Db::name('crontab'), $id);
    }

    /** 立即执行一次（不改变调度周期）。 */
    public static function runOnce(int $id): array
    {
        $task = self::assertExists($id);
        $now = time();
        $result = CrontabRunner::run($task);
        CrontabRunner::writeLog($task, $result, $now);
        return $result;
    }

    public static function logs(array $params): array
    {
        $query = Db::name('crontab_log');
        if (!empty($params['crontab_id'])) {
            $query->where('crontab_id', (int)$params['crontab_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** @param array<string,mixed> $data */
    private static function assertValid(array $data): void
    {
        $expression = (string)($data['expression'] ?? '');
        if (CronMatcher::nextRunTime($expression, time()) === null) {
            throw new ApiException(
                'cron 表达式非法，或未来一年内不会触发（六段：秒 分 时 日 月 周，如 0 0 */5 * * *）',
                422
            );
        }

        $target = (string)($data['target'] ?? '');
        if (!class_exists($target) || !is_subclass_of($target, CrontabTask::class)) {
            throw new ApiException('执行目标非法：必须是在代码中实现 CrontabTask 的类', 422);
        }
    }

    /** @return string */
    private static function encodeParams(mixed $params): string
    {
        if (is_string($params)) {
            return $params === '' ? '{}' : $params;
        }
        return (string)json_encode($params ?: new \stdClass(), JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private static function assertExists(int $id): array
    {
        $task = SoftDelete::apply(Db::name('crontab'))->where('id', $id)->find();
        if (!$task) {
            throw new ApiException('定时任务不存在', 404);
        }
        return $task;
    }
}
