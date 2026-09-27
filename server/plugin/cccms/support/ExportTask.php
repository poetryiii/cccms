<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\logic\LogLogic;
use support\Log;
use think\facade\Db;
use Throwable;
use Webman\Http\Response;

/**
 * 异步导出任务（P2-7）。
 *
 * ## 为什么异步
 *
 * 导出接口原本**同步**返回文件流，响应时间随数据量线性增长；前端 axios 超时 15s，
 * 大表导出（如 10 万行操作日志）会直接超时。改成「超阈值转后台任务」后，请求只负责
 * 落一条待处理记录就返回，实际生成由 `ExportTaskConsumer` 定时任务消费，
 * 在线请求不再被导出拖住。
 *
 * ## 职责边界
 *
 * - 本类只管**任务表 + 归档文件 + 状态机**，不管「怎么把数据变成 CSV」——那由
 *   `type` 分发给对应 Logic（`LogLogic::exportToFile`），各自用流式 keyset 分页写文件；
 * - 归档文件落在 `runtime/exports/`（**不进 `public/storage`**）：导出内容可能含敏感
 *   日志，绝不能放进无需鉴权的静态目录。下载必须走 `download()`（校验归属）。
 * - 任务按 `user_id` 归属，不参与数据权限作用域（导出的可见性由发起导出时的
 *   `filtered()` 作用域固化在参数里，消费时同一条作用域查询重新执行）。
 */
final class ExportTask
{
    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_DONE    = 2;
    public const STATUS_FAILED  = 3;
    public const STATUS_EXPIRED = 4;

    private const TABLE = 'export_task';

    /** 归档目录（相对 runtime） */
    private const DIR = 'exports';

    /** 单次消费最多处理的任务数（避免一次 tick 干太久） */
    private const BATCH = 10;

    /** 归档文件默认保留分钟数（可用 `export.keep_minutes` 覆盖） */
    public const DEFAULT_KEEP_MINUTES = 60;

    /**
     * 落一条待处理任务。
     *
     * `$params` 是**发起导出时的筛选参数**（含数据权限作用域所依据的当前用户上下文，
     * 但注意：作用域在消费时按「消费进程的上下文」重新执行，因此消费进程里必须能还原出
     * 相同的用户 —— 这里把 `user_id` 一并存下，消费时由 `LogLogic::exportToFile` 走
     * 同一份 `filtered()`；实际作用域来源见 LogLogic 的实现）。
     *
     * @param array<string,mixed> $params
     */
    public static function create(string $type, array $params, int $userId): int
    {
        return (int)Db::name(self::TABLE)->insertGetId([
            'type'        => $type,
            'user_id'     => $userId,
            'params'      => (string)json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'      => self::STATUS_PENDING,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 消费一批待处理任务（供定时任务调用），返回处理数。
     */
    public static function consume(): int
    {
        $pending = Db::name(self::TABLE)
            ->where('status', self::STATUS_PENDING)
            ->order('id', 'asc')
            ->limit(self::BATCH)
            ->select()->toArray();

        $processed = 0;
        foreach ($pending as $task) {
            if (self::runOne((int)$task['id'])) {
                $processed++;
            }
        }

        return $processed;
    }

    /** 本人任务列表（最新在前，封顶 100 条） */
    public static function list(int $userId): array
    {
        return Db::name(self::TABLE)
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->limit(100)
            ->select()->toArray();
    }

    /** 下载归档文件（校验归属 + 状态 + 文件仍存在） */
    public static function download(int $id, int $userId): Response
    {
        $task = Db::name(self::TABLE)->where('id', $id)->where('user_id', $userId)->find();
        if (!$task) {
            throw new ApiException(I18n::t('export.task_not_found'), 404);
        }
        if ((int)$task['status'] !== self::STATUS_DONE) {
            throw new ApiException(I18n::t('export.task_not_ready'), 409);
        }

        $full = self::path((string)$task['file_path']);
        if (!is_file($full)) {
            throw new ApiException(I18n::t('export.task_expired'), 410);
        }

        return (new Response())->download($full, (string)$task['file_name']);
    }

    /**
     * 清理过期归档文件：把「完成且早于保留期」的任务文件删掉、状态置为已过期。
     */
    public static function cleanup(int $minutes): int
    {
        $before = date('Y-m-d H:i:s', time() - $minutes * 60);
        $done   = Db::name(self::TABLE)
            ->where('status', self::STATUS_DONE)
            ->where('create_time', '<', $before)
            ->select()->toArray();

        $removed = 0;
        foreach ($done as $task) {
            $full = self::path((string)$task['file_path']);
            if ($full !== '' && is_file($full)) {
                @unlink($full);
            }
            Db::name(self::TABLE)->where('id', $task['id'])->update(['status' => self::STATUS_EXPIRED]);
            $removed++;
        }

        return $removed;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /** 执行一个任务（返回是否真的处理了它：抢占失败时不算） */
    private static function runOne(int $id): bool
    {
        // 先「认领」：把 pending 改成 running。并发下多个消费者只会有一个成功（update 命中 1 行）
        $claimed = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_RUNNING, 'update_time' => date('Y-m-d H:i:s')]);

        if ($claimed === 0) {
            return false;
        }

        $task   = Db::name(self::TABLE)->where('id', $id)->find();
        $params = json_decode((string)($task['params'] ?? '{}'), true);
        $params = is_array($params) ? $params : [];
        $target = self::dir() . DIRECTORY_SEPARATOR . $id . '.csv';

        try {
            $rows = self::generate((string)$task['type'], $params, $target);

            Db::name(self::TABLE)->where('id', $id)->update([
                'status'      => self::STATUS_DONE,
                'total_rows'  => $rows,
                'file_path'   => self::DIR . '/' . $id . '.csv',
                'file_name'   => self::fileName((string)$task['type']),
                'error'       => '',
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            @unlink($target);
            Db::name(self::TABLE)->where('id', $id)->update([
                'status'      => self::STATUS_FAILED,
                'error'       => mb_substr($e->getMessage(), 0, 255),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            Log::error('export task ' . $id . ' failed: ' . $e->getMessage());
        }

        return true;
    }

    /** 按类型分发到具体生成器，返回行数 */
    private static function generate(string $type, array $params, string $target): int
    {
        return match ($type) {
            'log' => LogLogic::exportToFile($target, $params),
            default => throw new ApiException(I18n::t('export.unknown_type'), 400),
        };
    }

    private static function fileName(string $type): string
    {
        return match ($type) {
            'log' => '操作日志.csv',
            default => 'export.csv',
        };
    }

    /** 归档根目录（绝对路径） */
    private static function dir(): string
    {
        $dir = self::baseDir() . DIRECTORY_SEPARATOR . self::DIR;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ApiException('无法创建导出目录', 500);
        }

        return $dir;
    }

    /** runtime 目录绝对路径 */
    private static function baseDir(): string
    {
        return base_path() . DIRECTORY_SEPARATOR . 'runtime';
    }

    /** 相对路径 → 绝对路径（防穿越：只允许 `exports/` 下） */
    private static function path(string $relative): string
    {
        $base = self::baseDir() . DIRECTORY_SEPARATOR;
        $full = $base . str_replace(['..', '\\'], ['', '/'], $relative);
        if (!str_starts_with($full, $base . self::DIR)) {
            return '';
        }

        return $full;
    }
}
