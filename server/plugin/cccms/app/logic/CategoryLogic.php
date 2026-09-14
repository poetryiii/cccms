<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/**
 * 通用分类逻辑（sys_category，按 module 隔离）。
 *
 * 服务两个模块：字典类型分类（dict）、附件分类（file）。
 *
 * 权限为什么按模块拆成两套 slug（cccms:dict:category_* / cccms:file:category_*）？
 * 因为「按钮节点必须先挂到一个 status=1 的菜单上」——若共用一个 cccms:category:*，
 * 挂任何一个菜单都会让另一个模块的授权互相牵连。拆开后各模块自洽，实现仍然只有这一份。
 */
final class CategoryLogic
{
    /** 允许的模块（白名单，避免前端传任意值污染数据） */
    private const MODULES = ['dict', 'file'];

    /** 模块 → [业务表, 外键列]，用于删除前的占用校验 */
    private const REF_TABLES = [
        'dict' => ['dict_type', 'category_id'],
        'file' => ['file', 'category_id'],
    ];

    /** 允许写入的字段（白名单，避免前端塞入 id / module 之类的列） */
    private const FIELDS = ['parent_id', 'name', 'sort', 'status', 'remark'];

    /**
     * 分类树（按模块）。
     *
     * `$trashed=true` 时返回**平铺**的已删分类：回收站里的节点，其父节点可能还活着，
     * 拼树会漏掉「父未删、子已删」的节点。
     */
    public static function tree(string $module, bool $trashed = false): array
    {
        self::assertModule($module);
        $all = SoftDelete::scope(Db::name('category'), $trashed)
            ->where('module', $module)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()->toArray();

        return $trashed ? $all : self::buildTree($all, 0);
    }

    /**
     * 把前端传来的 category_id 解析成查询条件。
     *
     * 约定：不传 / 0 = 不筛选（全部）；-1 = 未分类（category_id = 0）；>0 = 该分类及其所有下级。
     *
     * @param  mixed      $categoryId
     * @return int[]|null null 表示不加条件
     */
    public static function scopeIds(mixed $categoryId): ?array
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $id = (int)$categoryId;
        if ($id === 0) {
            return null;
        }

        return $id < 0 ? [0] : self::subtreeIds($id);
    }

    /** 分类自身 + 所有后代 id（选中父分类时同时统计子分类的数据）。 */
    public static function subtreeIds(int $id): array
    {
        $parents = SoftDelete::apply(Db::name('category'))->column('parent_id', 'id');
        $ids     = [$id];
        $stack   = [$id];

        while ($stack) {
            $current = (int)array_pop($stack);
            foreach ($parents as $childId => $parentId) {
                if ((int)$parentId === $current && !in_array((int)$childId, $ids, true)) {
                    $ids[]   = (int)$childId;
                    $stack[] = (int)$childId;
                }
            }
        }

        return $ids;
    }

    public static function create(string $module, array $data): int
    {
        self::assertModule($module);

        $data = self::pick($data);
        if (($data['name'] ?? '') === '') {
            throw new ApiException('分类名不能为空', 422);
        }

        $data['module']      = $module;
        $data['create_time'] = date('Y-m-d H:i:s');

        return (int)Db::name('category')->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        $row = self::assertExists($id);
        $data = self::pick($data);

        if (array_key_exists('name', $data) && (string)$data['name'] === '') {
            throw new ApiException('分类名不能为空', 422);
        }

        // 不允许把自己或自己的下级设为上级，否则树会成环
        if (array_key_exists('parent_id', $data)) {
            $parentId = (int)$data['parent_id'];
            if ($parentId === $id || in_array($parentId, self::subtreeIds($id), true)) {
                throw new ApiException('上级分类不能是自己或自己的下级', 422);
            }
        }

        if (!$data) {
            return;
        }

        $data['update_time'] = date('Y-m-d H:i:s');
        Db::name('category')->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        $row = self::assertExists($id);

        if (SoftDelete::apply(Db::name('category'))->where('parent_id', $id)->count() > 0) {
            throw new ApiException('存在子分类，无法删除', 422);
        }

        $ref = self::REF_TABLES[$row['module']] ?? null;
        if ($ref !== null && SoftDelete::apply(Db::name($ref[0]))->where($ref[1], $id)->count() > 0) {
            throw new ApiException('该分类下还有数据，请先移动到其他分类', 422);
        }

        // 软删除：进回收站
        SoftDelete::remove(Db::name('category'), $id);
    }

    private static function assertModule(string $module): void
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new ApiException('未知的分类模块：' . $module, 422);
        }
    }

    /** @param array<string,mixed> $data */
    private static function pick(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    private static function assertExists(int $id): array
    {
        $row = SoftDelete::apply(Db::name('category'))->where('id', $id)->find();
        if (!$row) {
            throw new ApiException('分类不存在', 404);
        }

        return $row;
    }

    private static function buildTree(array $items, int $parentId): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)$item['parent_id'] === $parentId) {
                $item['children'] = self::buildTree($items, (int)$item['id']);
                $tree[]           = $item;
            }
        }

        return $tree;
    }
}
