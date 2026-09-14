<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\FileStorage;
use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/**
 * 回收站：统一管理各模块「软删除」数据的**恢复 / 彻底删除**。
 *
 * 列表刻意不在这里做：各模块页面点「回收站」按钮后，用的是**自己的列表接口 + `trashed=1`**
 * （见 `SoftDelete::listQuery`），所以同一张表、同一套列，只是换了数据源。
 * 这里只负责两个写操作：按 `type` 找到表，并做恢复前的约束校验。
 *
 * 恢复约束：
 *   - 带唯一键的表（user.username / role.code / post.code / dict_type.type）
 *     若唯一值已被新数据占用，恢复会失败，这里提前给出可读提示；
 *   - 部门 / 分类这类树结构，上级还在回收站时不允许恢复，避免出现「挂在已删节点下」的孤儿。
 */
final class RecycleLogic
{
    /** 类型 => [表名, 中文名, 名称列, 次要列(可为空)] */
    private const TYPES = [
        'user'      => ['table' => 'user',      'label' => '用户',        'name' => 'username',      'sub' => 'nickname'],
        'role'      => ['table' => 'role',      'label' => '角色',        'name' => 'name',          'sub' => 'code'],
        'dept'      => ['table' => 'dept',      'label' => '部门',        'name' => 'name',          'sub' => ''],
        'post'      => ['table' => 'post',      'label' => '岗位',        'name' => 'name',          'sub' => 'code'],
        'dict_type' => ['table' => 'dict_type', 'label' => '字典类型',    'name' => 'name',          'sub' => 'type'],
        'dict_data' => ['table' => 'dict_data', 'label' => '字典数据',    'name' => 'label',         'sub' => 'value'],
        'category'  => ['table' => 'category',  'label' => '分类',        'name' => 'name',          'sub' => 'module'],
        'crontab'   => ['table' => 'crontab',   'label' => '定时任务',    'name' => 'name',          'sub' => 'expression'],
        'data_rule' => ['table' => 'data_rule', 'label' => '数据权限规则', 'name' => 'name',         'sub' => 'field'],
        'file'      => ['table' => 'file',      'label' => '附件',        'name' => 'original_name', 'sub' => 'ext'],
        'menu'      => ['table' => 'menu',      'label' => '菜单/权限节点', 'name' => 'title',        'sub' => 'node'],
    ];

    /** 带唯一键的表：恢复前要检查唯一值是否已被占用 */
    private const UNIQUE_COLUMN = [
        'user'      => 'username',
        'role'      => 'code',
        'post'      => 'code',
        'dict_type' => 'type',
        'menu'      => 'node',
    ];

    /** 有上级的类型：恢复前要检查上级是否还在回收站，避免出现「挂在已删节点下」的孤儿 */
    private const PARENTS = [
        'dept'      => ['column' => 'parent_id', 'table' => 'dept'],
        'category'  => ['column' => 'parent_id', 'table' => 'category'],
        'dict_data' => ['column' => 'type_id',   'table' => 'dict_type'],
        'menu'      => ['column' => 'parent_id', 'table' => 'menu'],
    ];

    public static function restore(string $type, array $ids): int
    {
        $meta = self::meta($type);
        $ids  = self::ids($ids);

        self::assertRestorable($type, $meta, $ids);

        return SoftDelete::restore(Db::name($meta['table']), $ids);
    }

    /** 彻底删除（附件连同物理文件一起删） */
    public static function forceDelete(string $type, array $ids): int
    {
        $meta = self::meta($type);
        $ids  = self::ids($ids);

        if ($type === 'file') {
            // 软删阶段刻意保留物理文件，只有「彻底删除」才真正落盘删除
            $paths = Db::name('file')->whereIn('id', $ids)->column('path');
            foreach ($paths as $path) {
                FileStorage::delete((string)$path);
            }
        }

        if ($type === 'menu') {
            // 软删阶段刻意保留 role_node 授权（便于恢复）；彻底删除时一并清掉，避免残留孤儿授权
            $nodes = SoftDelete::onlyTrashed(Db::name('menu'))->whereIn('id', $ids)->column('node');
            foreach ($nodes as $node) {
                if ((string)$node !== '') {
                    Db::name('role_node')->where('node', (string)$node)->delete();
                }
            }
        }

        return SoftDelete::force(Db::name($meta['table']), $ids);
    }

    /** 恢复前的约束检查 */
    private static function assertRestorable(string $type, array $meta, array $ids): void
    {
        // ① 唯一值是否被新数据占用
        $unique = self::UNIQUE_COLUMN[$type] ?? null;
        if ($unique !== null) {
            $values = SoftDelete::onlyTrashed(Db::name($meta['table']))
                ->whereIn('id', $ids)
                ->column($unique);
            foreach ($values as $value) {
                $taken = Db::name($meta['table'])->where($unique, $value)->count();
                if ($taken > 0) {
                    throw new ApiException("「{$value}」已被新数据占用，请先改名或彻底删除新数据", 422);
                }
            }
        }

        // ② 上级还在回收站时不允许恢复
        $parent = self::PARENTS[$type] ?? null;
        if ($parent !== null) {
            $parentIds = array_values(array_filter(array_map(
                'intval',
                SoftDelete::onlyTrashed(Db::name($meta['table']))->whereIn('id', $ids)->column($parent['column'])
            )));
            if ($parentIds !== []) {
                $trashed = SoftDelete::onlyTrashed(Db::name($parent['table']))->whereIn('id', $parentIds)->count();
                if ($trashed > 0) {
                    throw new ApiException('上级节点还在回收站，请先恢复上级', 422);
                }
            }
        }
    }

    /** @return array{table:string,label:string,name:string,sub:string} */
    private static function meta(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new ApiException('未知的回收站类型：' . $type, 422);
        }

        return self::TYPES[$type];
    }

    /** @return int[] */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if ($ids === []) {
            throw new ApiException('请选择要操作的数据', 422);
        }

        return $ids;
    }
}
