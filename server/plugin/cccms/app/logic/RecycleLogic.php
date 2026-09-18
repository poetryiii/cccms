<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\File;
use plugin\cccms\app\model\Menu;
use plugin\cccms\app\model\RoleNode;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FileStorage;
use plugin\cccms\support\PermissionCache;
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
        'notice'    => ['table' => 'notice',    'label' => '通知公告',    'name' => 'title',         'sub' => 'type'],
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

    /** 业务插件声明的回收站类型缓存（plugin/{插件}/db/recycle.php） */
    private static ?array $pluginTypes = null;

    /**
     * 业务插件声明的回收站类型。
     *
     * 与 `db/menu.php` 同一约定：插件在 `db/recycle.php` 里声明
     * `['ks_subject' => ['table' => 'ks_subject', 'label' => '主体', 'name' => 'name', 'sub' => 'credit_code']]`。
     *
     * 这类表的表名是**完整表名**（如 `ks_*`，不走全局 `sys_` 前缀），
     * 因此取查询构造器时走 `Db::table()` 而非 `Db::name()`。
     *
     * @return array<string,array{table:string,label:string,name:string,sub:string,full:bool}>
     */
    private static function pluginTypes(): array
    {
        if (self::$pluginTypes !== null) {
            return self::$pluginTypes;
        }

        $types = [];
        foreach (glob(base_path() . '/plugin/*/db/recycle.php') ?: [] as $file) {
            $declared = (array)(include $file);
            foreach ($declared as $type => $meta) {
                if (!is_array($meta) || empty($meta['table'])) {
                    continue;
                }
                $types[(string)$type] = [
                    'table' => (string)$meta['table'],
                    'label' => (string)($meta['label'] ?? $type),
                    'name'  => (string)($meta['name'] ?? 'name'),
                    'sub'   => (string)($meta['sub'] ?? ''),
                    'full'  => true,
                ];
            }
        }

        return self::$pluginTypes = $types;
    }

    /** 按类型取查询构造器：内置表走前缀（Db::name），插件表走完整表名（Db::table） */
    private static function query(array $meta)
    {
        return !empty($meta['full']) ? Db::table($meta['table']) : Db::name($meta['table']);
    }

    public static function restore(string $type, array $ids): int
    {
        $meta = self::meta($type);
        $ids  = self::ids($ids);

        self::assertRestorable($type, $meta, $ids);

        $affected = SoftDelete::restore(self::query($meta), $ids);

        // 恢复的可能是角色 / 菜单 / 用户：权限集合要立即重算
        PermissionCache::bump();

        return $affected;
    }

    /** 彻底删除（附件连同物理文件一起删） */
    public static function forceDelete(string $type, array $ids): int
    {
        $meta = self::meta($type);
        $ids  = self::ids($ids);

        if ($type === 'file') {
            // 软删阶段刻意保留物理文件，只有「彻底删除」才真正落盘删除。
            // withTrashed()：要读的正是回收站里的行，模型默认会排除它们
            $paths = File::withTrashed()->whereIn('id', $ids)->column('path');
            foreach ($paths as $path) {
                FileStorage::delete((string)$path);
            }
        }

        if ($type === 'menu') {
            // 软删阶段刻意保留 role_node 授权（便于恢复）；彻底删除时一并清掉，避免残留孤儿授权
            $nodes = Menu::onlyTrashed()->whereIn('id', $ids)->column('node');
            foreach ($nodes as $node) {
                if ((string)$node !== '') {
                    RoleNode::where('node', (string)$node)->delete();
                }
            }
        }

        if ($type === 'notice') {
            // 彻底删除公告时一并清理定向投放目标与已读记录，避免残留孤儿
            Db::name('notice_target')->whereIn('notice_id', $ids)->delete();
            Db::name('notice_read')->whereIn('notice_id', $ids)->delete();
        }

        $affected = SoftDelete::force(self::query($meta), $ids);

        // 彻底删除可能清掉了 role_node 授权，或让「回收站里的同名角色」不再占用标识
        PermissionCache::bump();

        return $affected;
    }

    /** 恢复前的约束检查 */
    private static function assertRestorable(string $type, array $meta, array $ids): void
    {
        // ① 唯一值是否被新数据占用
        $unique = self::UNIQUE_COLUMN[$type] ?? null;
        if ($unique !== null) {
            $values = SoftDelete::onlyTrashed(self::query($meta))
                ->whereIn('id', $ids)
                ->column($unique);
            foreach ($values as $value) {
                // 必须是「活着的行」里查重：待恢复的这行本身也在库里，
                // 不过滤已删数据的话会把自己算成「已被占用」，恢复永远失败
                $taken = SoftDelete::apply(self::query($meta))->where($unique, $value)->count();
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
                SoftDelete::onlyTrashed(self::query($meta))->whereIn('id', $ids)->column($parent['column'])
            )));
            if ($parentIds !== []) {
                $trashed = SoftDelete::onlyTrashed(Db::name($parent['table']))->whereIn('id', $parentIds)->count();
                if ($trashed > 0) {
                    throw new ApiException('上级节点还在回收站，请先恢复上级', 422);
                }
            }
        }
    }

    /** @return array{table:string,label:string,name:string,sub:string,full:bool} */
    private static function meta(string $type): array
    {
        if (isset(self::TYPES[$type])) {
            return self::TYPES[$type] + ['full' => false];
        }

        $plugin = self::pluginTypes();
        if (isset($plugin[$type])) {
            return $plugin[$type];
        }

        throw new ApiException('未知的回收站类型：' . $type, 422);
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
