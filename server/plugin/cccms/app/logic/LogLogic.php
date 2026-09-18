<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\OperationLog;
use plugin\cccms\support\Csv;
use Webman\Http\Response;

/**
 * 操作日志逻辑。
 *
 * 查询统一走 `OperationLog` 模型：它**参与**数据权限（仅本人 = 自己的日志、
 * 本部门 = 可见部门成员的日志，见模型里的 `$dataScope`），所以越权数据在列表与
 * 删除/清空上都自然不可见、不可动，这里不需要额外判定。
 *
 * 日志表没有软删除列，删除即物理删除。
 */
final class LogLogic
{
    /** 导出上限：避免一次导出把内存打满 */
    private const EXPORT_LIMIT = 10000;

    public static function paginate(array $params): array
    {
        $query = self::filtered($params);

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** 导出 CSV（按当前筛选与数据范围） */
    public static function export(array $params): Response
    {
        $rows = self::filtered($params)->order('id', 'desc')->limit(self::EXPORT_LIMIT)->select()->toArray();

        $data = array_map(static fn (array $row): array => [
            (string)($row['id'] ?? ''),
            (string)($row['username'] ?? ''),
            (string)($row['method'] ?? ''),
            (string)($row['path'] ?? ''),
            (string)($row['node'] ?? ''),
            (string)($row['title'] ?? ''),
            (string)($row['ip'] ?? ''),
            (string)($row['status_code'] ?? ''),
            (string)($row['cost'] ?? ''),
            (string)($row['trace_id'] ?? ''),
            (string)($row['create_time'] ?? ''),
        ], $rows);

        return Csv::download(
            '操作日志',
            ['ID', '账号', '方法', '路径', '节点', '操作名', 'IP', '状态码', '耗时(ms)', '链路ID', '时间'],
            $data
        );
    }

    /** @return \think\db\BaseQuery */
    private static function filtered(array $params)
    {
        $query = OperationLog::newScopedQuery();
        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        if (!empty($params['method'])) {
            $query->where('method', $params['method']);
        }
        if (!empty($params['path'])) {
            $query->where('path', 'like', '%' . $params['path'] . '%');
        }
        // 语义化筛选：按权限节点或操作名找（比记 path 直观）
        if (!empty($params['node'])) {
            $query->where('node', 'like', '%' . $params['node'] . '%');
        }
        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        // 链路 ID：拿到一次请求的报错 trace 后可直接检索
        if (!empty($params['trace_id'])) {
            $query->where('trace_id', trim((string)$params['trace_id']));
        }
        if (!empty($params['start'])) {
            $query->where('create_time', '>=', $params['start'] . ' 00:00:00');
        }
        if (!empty($params['end'])) {
            $query->where('create_time', '<=', $params['end'] . ' 23:59:59');
        }

        return $query;
    }

    public static function delete(array $ids): void
    {
        if ($ids) {
            // 带作用域：只能删掉自己看得到的日志
            OperationLog::newScopedQuery()->whereIn('id', array_map('intval', $ids))->delete();
        }
    }

    public static function clear(): void
    {
        // 同理：清空的是「当前用户可见范围」内的日志，而不是全表
        OperationLog::newScopedQuery()->where('id', '>', 0)->delete();
    }
}
