<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\UserPost;

/**
 * 岗位逻辑。
 *
 * 查询统一走 `Post` 模型（当前声明为**不参与**数据权限，见模型里的 `$dataScope`），
 * 这样「表怎么隔离」只在模型里声明一次，而不是散在各处查询里。
 */
final class PostLogic
{
    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列
        $query = !empty($params['trashed']) ? Post::onlyTrashed() : Post::newScopedQuery();
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public static function create(array $data): int
    {
        // 新增还没有归属，插入语句不需要数据权限条件
        return (int)Post::withoutGlobalScope()->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        self::assertExists($id);
        Post::newScopedQuery()->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        if (UserPost::where('post_id', $id)->count() > 0) {
            throw new \RuntimeException('岗位下存在用户，无法删除');
        }

        // 软删除：进回收站
        Post::destroy($id);
    }

    private static function assertExists(int $id): void
    {
        // 存在性校验看全量（含回收站），显式跳出作用域
        if (!Post::withoutGlobalScope()->where('id', $id)->find()) {
            throw new \RuntimeException('岗位不存在');
        }
    }
}
