<?php

declare(strict_types=1);

use plugin\cccms\support\ApiException;
use plugin\cccms\support\ExportTask;
use think\facade\Db;
use Webman\Http\Response;

/**
 * P2-7：异步导出任务（落任务 → 消费生成归档 → 下载 / 归属校验 → 过期清理）。
 *
 * 消费会真实走一遍 `LogLogic::exportToFile`（keyset 分页流式写 CSV），
 * 因此需要真实 MySQL（`sys_log` 与 `sys_export_task`）。
 */
return static function (): void {
    suite('异步导出任务（P2-7）');

    $cleanup = static function (): void {
        // 只清理**本测试**（user_id=99999）的任务与归档文件，绝不触碰其它用户 / 真实数据。
        // 曾用 `glob(runtime/exports/*.csv)` 兜底清文件，会把真实用户刚导出的文件一起删掉
        // （下载因此报「已过期」），所以这里改为按测试任务自己的 file_path 精确删除。
        $rows = Db::name('export_task')->where('user_id', 99999)->select()->toArray();
        foreach ($rows as $row) {
            if (($row['file_path'] ?? '') !== '') {
                @unlink(base_path() . '/runtime/' . $row['file_path']);
            }
        }
        Db::name('export_task')->where('user_id', 99999)->delete();
    };

    test('落任务 → 消费 → 生成归档文件 → 可列出', function () use ($cleanup): void {
        try {
            $taskId = ExportTask::create('log', ['username' => '__itest__export'], 99999);
            ok($taskId > 0, '应返回任务 id');

            // 待处理状态
            $list = ExportTask::list(99999);
            same(ExportTask::STATUS_PENDING, (int)$list[0]['status'], '新任务应为待处理');

            // 消费一次
            $processed = ExportTask::consume();
            ok($processed >= 1, '消费应至少处理 1 个任务');

            $after = Db::name('export_task')->where('id', $taskId)->find();
            same(ExportTask::STATUS_DONE, (int)$after['status'], '消费后应标记完成');
            ok((int)$after['total_rows'] >= 0, 'total_rows 应落库');
            ok($after['file_path'] !== '', '应记录归档路径');

            $full = base_path() . '/runtime/' . $after['file_path'];
            ok(is_file($full), '归档 CSV 应真实落盘：' . $full);
            ok((int)(filesize($full) ?: 0) > 0, '归档文件非空');
        } finally {
            $cleanup();
        }
    }, true);

    test('下载只认本人任务（归属校验）', function () use ($cleanup): void {
        try {
            $taskId = ExportTask::create('log', [], 99999);
            ExportTask::consume();

            // 本人可下载
            $response = ExportTask::download($taskId, 99999);
            ok($response instanceof Response, '本人下载应返回文件响应');

            // 他人不可下载（表现为不存在，不泄露存在性）
            try {
                ExportTask::download($taskId, 88888);
                fail('他人下载应抛异常，但没有抛');
            } catch (ApiException $e) {
                contains('不存在', $e->getMessage(), '越权下载应报「不存在」');
            }
        } finally {
            $cleanup();
        }
    }, true);

    test('处理中的任务不可下载（待处理会同步补生成，只有处理中/失败才拒绝）', function () use ($cleanup): void {
        try {
            $taskId = ExportTask::create('log', [], 99999);
            // 手动置为「处理中」：下载时不会被同步补生成（只对 pending 兜底），应拒绝
            Db::name('export_task')->where('id', $taskId)->update(['status' => ExportTask::STATUS_RUNNING]);
            try {
                ExportTask::download($taskId, 99999);
                fail('处理中任务下载应抛异常，但没有抛');
            } catch (ApiException $e) {
                contains('未完成', $e->getMessage(), '处理中下载应提示稍后');
            }
        } finally {
            $cleanup();
        }
    }, true);

    test('待处理任务下载时同步补生成（Windows 无定时进程的兜底）', function () use ($cleanup): void {
        try {
            $taskId = ExportTask::create('log', [], 99999);
            // 不经过 consume()，直接下载：应触发同步生成并返回文件
            $response = ExportTask::download($taskId, 99999);
            ok($response instanceof Response, '待处理任务下载应同步生成并返回文件');

            $after = Db::name('export_task')->where('id', $taskId)->find();
            same(ExportTask::STATUS_DONE, (int)$after['status'], '下载后任务应转为完成');
            ok(is_file(base_path() . '/runtime/' . $after['file_path']), '文件应已生成');
        } finally {
            $cleanup();
        }
    }, true);

    test('过期清理：完成且超过保留期的任务置为已过期并删文件', function () use ($cleanup): void {
        try {
            $taskId = ExportTask::create('log', [], 99999);
            ExportTask::consume();

            $before = Db::name('export_task')->where('id', $taskId)->find();
            // 把创建时间拨到 2 小时前，模拟「早已过期」
            Db::name('export_task')->where('id', $taskId)->update([
                'create_time' => date('Y-m-d H:i:s', time() - 7200),
            ]);

            $removed = ExportTask::cleanup(60);
            ok($removed >= 1, '应清理至少 1 个过期任务');

            $after = Db::name('export_task')->where('id', $taskId)->find();
            same(ExportTask::STATUS_EXPIRED, (int)$after['status'], '清理后状态应为已过期');
            ok(!is_file(base_path() . '/runtime/' . $before['file_path']), '过期文件应被删除');
        } finally {
            $cleanup();
        }
    }, true);

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
