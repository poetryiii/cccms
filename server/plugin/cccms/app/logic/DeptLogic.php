<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/** 部门逻辑。 */
final class DeptLogic
{
    /**
     * 部门树。
     *
     * `$trashed=true` 时返回**平铺**的已删部门：回收站里的节点，其父节点可能还活着，
     * 只拿已删行去拼树会把「父未删、子已删」的节点直接漏掉 —— 反而恢复不了了。
     */
    public static function tree(bool $trashed = false): array
    {
        $all = SoftDelete::scope(Db::name('dept'), $trashed)
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return $trashed ? $all : self::buildTree($all, 0);
    }

    public static function create(array $data): int
    {
        return (int)Db::name('dept')->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        if (!SoftDelete::apply(Db::name('dept'))->where('id', $id)->find()) {
            throw new \RuntimeException('部门不存在');
        }
        Db::name('dept')->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        if (SoftDelete::apply(Db::name('dept'))->where('parent_id', $id)->count() > 0) {
            throw new \RuntimeException('存在子部门，无法删除');
        }
        if (Db::name('user_dept')->where('dept_id', $id)->count() > 0) {
            throw new \RuntimeException('部门下存在用户，无法删除');
        }
        // 软删除：进回收站；dept_role 保留，恢复后部门角色原样回来
        SoftDelete::remove(Db::name('dept'), $id);
    }

    private static function buildTree(array $items, int $parentId): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)$item['parent_id'] === $parentId) {
                $item['children'] = self::buildTree($items, (int)$item['id']);
                $tree[] = $item;
            }
        }
        return $tree;
    }
}
