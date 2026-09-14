<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use think\facade\Db;

/** 操作日志逻辑。 */
final class LogLogic
{
    public static function paginate(array $params): array
    {
        $query = Db::name('log');
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
        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();
        return ['total' => $total, 'list' => $list];
    }

    public static function delete(array $ids): void
    {
        if ($ids) {
            Db::name('log')->where('id', 'in', array_map('intval', $ids))->delete();
        }
    }

    public static function clear(): void
    {
        Db::name('log')->where('id', '>', 0)->delete();
    }
}
