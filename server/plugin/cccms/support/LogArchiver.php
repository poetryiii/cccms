<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\OperationLog;
use plugin\cccms\support\storage\StorageManager;

/**
 * 历史日志归档：把 sys_log 的冷数据序列化为 JSONL(gzip) 上传对象存储，成功后再从主库删除。
 *
 * 为什么放在 support：命令（手动 / CI）与定时任务（LogArchiveTask）要共用同一套逻辑，
 * 写两遍必然漂移。
 *
 * 核心安全约束（改动时务必保持）：
 *   1. **先上传成功、再删除**：上传抛异常立即中止，绝不出现「删了没存」；
 *   2. 按批「上传 → 按 id 删除」，天然可重入：删除成功后再跑不会重复选中；
 *      即便「上传成功但删除失败」，下次只是重复归档同一批（有冗余），不会丢数据；
 *   3. 归档前对 params / result 二次脱敏（历史记录可能早于脱敏规则的补充）。
 */
final class LogArchiver
{
    /** 破坏性操作下限：归档会删主库数据，天数过小等于误删，硬性兜底 */
    public const MIN_DAYS = 7;

    /** 单批上限，防止 --batch 传超大值把内存打满 */
    private const MAX_BATCH = 50000;

    /**
     * 执行一次归档。
     *
     * @param  array{days?:int,batch?:int,path?:string,dry_run?:bool} $options
     * @return array{scanned:int,archived:int,files:int,remaining:int,dry_run:bool,driver:string,cutoff:string,path:string}
     */
    public static function archive(array $options = []): array
    {
        $cfg   = (array)config('plugin.cccms.log.archive', []);
        $days  = (int)($options['days'] ?? $cfg['days'] ?? 30);
        $batch = (int)($options['batch'] ?? $cfg['batch'] ?? 1000);
        $pathFilter = trim((string)($options['path'] ?? ''));
        $dry        = (bool)($options['dry_run'] ?? false);

        // 破坏性操作：天数强制不小于下限，batch 收敛到 [1, MAX_BATCH]
        $days  = max($days, self::MIN_DAYS);
        $batch = min(max($batch, 1), self::MAX_BATCH);

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $prefix = trim((string)($cfg['path'] ?? 'log-archive'), '/');

        $driverName = (string)($cfg['driver'] ?? '');
        $driverName = $driverName !== '' ? $driverName : StorageManager::current();

        // 逃生口：归档是系统级动作，必须扫描全量日志，绝不能受当前用户的数据权限约束。
        // 显式 withoutGlobalScope() 并从代码可见（见 docs/08 §七）。
        // path 精确匹配可只归档某一类记录，如 --path=/auth/login 只归档登录日志；空 = 全部。
        $build = static function () use ($cutoff, $pathFilter) {
            $query = OperationLog::withoutGlobalScope()->where('create_time', '<', $cutoff);
            if ($pathFilter !== '') {
                $query->where('path', $pathFilter);
            }

            return $query;
        };

        $scanned = (int)$build()->count();

        if ($dry) {
            return [
                'scanned'   => $scanned,
                'archived'  => 0,
                'files'     => 0,
                'remaining' => $scanned,
                'dry_run'   => true,
                'driver'    => $driverName,
                'cutoff'    => $cutoff,
                'path'      => $pathFilter,
            ];
        }

        $driver   = StorageManager::driver($driverName);
        $archived = 0;
        $files    = 0;
        $seq      = 0;

        while (true) {
            // 每轮都取「最旧的 batch 条」：上一轮已删除，天然向前推进，不需要 offset
            $rows = $build()->order('id', 'asc')->limit($batch)->select();
            if ($rows->isEmpty()) {
                break;
            }

            $ids   = [];
            $lines = [];
            foreach ($rows as $row) {
                $data = $row->getData();
                // 模型把 create_time 读成 DateTime 对象，直接 json_encode 会变成 {}（字段丢失），
                // 必须显式格式化回字符串，否则归档文件里的时间字段不可读。
                if (isset($data['create_time']) && !is_string($data['create_time'])) {
                    $ct = $data['create_time'];
                    $data['create_time'] = $ct instanceof \DateTimeInterface
                        ? $ct->format('Y-m-d H:i:s')
                        : (string)$ct;
                }
                // 归档前再脱敏一次：历史记录可能早于脱敏规则
                $data['params'] = LogRedactor::redactJson(isset($data['params']) ? (string)$data['params'] : null);
                $data['result'] = LogRedactor::redactJson(isset($data['result']) ? (string)$data['result'] : null);

                $ids[]   = (int)$data['id'];
                $lines[] = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $seq++;
            $path = sprintf(
                '%s/%s/%s/log-%s-%d.jsonl.gz',
                $prefix,
                date('Y'),
                date('m'),
                date('Ymd-His'),
                $seq
            );

            $content = gzencode(implode("\n", $lines) . "\n", 9);
            if ($content === false) {
                throw new ApiException('gzip 压缩失败', 500);
            }

            // ① 先上传：失败会抛异常并中止，主库记录原封不动
            $driver->put($content, $path);
            $files++;

            // ② 上传成功后再删：单条 DELETE ... IN 原子；此处失败同样抛出，留待下次重试
            OperationLog::withoutGlobalScope()->whereIn('id', $ids)->delete();
            $archived += count($ids);
        }

        return [
            'scanned'   => $scanned,
            'archived'  => $archived,
            'files'     => $files,
            'remaining' => max(0, $scanned - $archived),
            'dry_run'   => false,
            'driver'    => $driverName,
            'cutoff'    => $cutoff,
            'path'      => $pathFilter,
        ];
    }
}
