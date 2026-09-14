<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/** 岗位逻辑。 */
final class PostLogic
{
    public static function paginate(array $params): array
    {
        $query = SoftDelete::listQuery('post', $params);
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
        return (int)Db::name('post')->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        if (!SoftDelete::apply(Db::name('post'))->where('id', $id)->find()) {
            throw new \RuntimeException('岗位不存在');
        }
        Db::name('post')->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        if (Db::name('user_post')->where('post_id', $id)->count() > 0) {
            throw new \RuntimeException('岗位下存在用户，无法删除');
        }
        // 软删除：进回收站
        SoftDelete::remove(Db::name('post'), $id);
    }
}
