<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Crontab;
use plugin\cccms\app\model\CrontabLog;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\CronMatcher;
use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\CrontabTask;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\facade\Db;

/**
 * 定时任务逻辑。
 *
 * 查询统一走 `Crontab` 模型：该表既无归属列也无部门列，模型里声明了 `no_baseline`
 * （见 `app/model/Crontab.php`），隔离完全交给自定义行级规则；
 * 同时它也是**调度进程**的数据源，CLI 下没有当前用户，作用域自动跳过。
 */
final class CrontabLogic
{
    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除）
        $query = !empty($params['trashed']) ? Crontab::onlyTrashed() : Crontab::newScopedQuery();
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

    /** 可作为调度目标的任务类（实现 CrontabTask）。扫描所有插件的 plugin/*\/command/task。 */
    public static function targets(): array
    {
        $result = [];
        foreach (glob(base_path() . '/plugin/*/command/task', GLOB_ONLYDIR) ?: [] as $dir) {
            // .../plugin/{插件}/command/task → 命名空间 plugin\{插件}\command\task\
            $plugin    = basename(dirname($dir, 2));
            $namespace = 'plugin\\' . $plugin . '\\command\\task\\';
            $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel   = substr($file->getPathname(), strlen($dir) + 1, -4);
                $class = $namespace . str_replace(DIRECTORY_SEPARATOR, '\\', (string)$rel);
                if (class_exists($class) && is_subclass_of($class, CrontabTask::class)) {
                    // label 带插件前缀，避免多插件同名任务类无法区分（如两个插件都有 DailyReportTask）
                    $result[] = [
                        'class' => $class,
                        'label' => $plugin . '/' . substr((string)strrchr($class, '\\'), 1),
                    ];
                }
            }
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return $result;
    }

    public static function create(array $data): int
    {
        self::assertValid($data);
        $data['params'] = self::encodeParams($data['params'] ?? []);
        $next = CronMatcher::nextRunTime((string)$data['expression'], time());
        $data['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;

        // 新增还没有归属，插入语句不需要数据权限条件
        return (int)Crontab::withoutGlobalScope()->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        self::assertInScope($id);
        self::assertValid($data);
        if (array_key_exists('params', $data)) {
            $data['params'] = self::encodeParams($data['params']);
        }
        $next = CronMatcher::nextRunTime((string)$data['expression'], time());
        $data['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;

        Crontab::newScopedQuery()->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertInScope($id);
        // 软删除：进回收站。执行日志是历史记录，刻意保留（调度进程查询已自动排除已删任务）
        Crontab::destroy($id);
    }

    /** 立即执行一次（不改变调度周期）。 */
    public static function runOnce(int $id): array
    {
        $task = self::assertInScope($id);
        $now = time();
        $result = CrontabRunner::run($task);
        CrontabRunner::writeLog($task, $result, $now);
        return $result;
    }

    public static function logs(array $params): array
    {
        // 执行日志随任务归属（模型声明为不参与数据权限），不单独做范围判定
        $query = CrontabLog::newScopedQuery();
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

    /**
     * 数据范围校验：在范围内才返回任务实体（越权 403、不存在 404）。
     *
     * 范围由 `Crontab` 模型的全局作用域注入；`withoutGlobalScope()` 那次查库只用于
     * 区分「不存在」与「越权」，不参与业务。
     *
     * @return array<string,mixed>
     */
    private static function assertInScope(int $id): array
    {
        $task = Crontab::newScopedQuery()->where('id', $id)->find();
        if ($task) {
            return $task->toArray();
        }

        if (!Crontab::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException('定时任务不存在', 404);
        }

        throw new ApiException('无权操作该定时任务', 403);
    }
}
