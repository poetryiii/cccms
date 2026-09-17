<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\RoleNode;
use plugin\cccms\support\ApiException;

/**
 * 角色管理逻辑（含继承与节点授权）。
 *
 * 查询统一走 `Role` / `RoleNode` 模型；角色表当前声明为**不参与**数据权限
 * （组织架构数据，见 `app/model/Role.php`），因此作用域是空操作 ——
 * 但入口统一后，将来若要给角色加隔离，只改模型声明即可生效。
 *
 * 需要看到全量数据的判定（存在性、唯一性）一律显式 `withoutGlobalScope()`。
 */
final class RoleLogic
{
    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除）；软删除过滤由模型层承担
        $query = !empty($params['trashed']) ? Role::onlyTrashed() : Role::newScopedQuery();
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        // 左侧角色树选中节点后，只列出该角色及其所有下级
        if (!empty($params['node_id'])) {
            $query->whereIn('id', self::subtreeIds((int)$params['node_id']));
        }
        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** 角色自身 + 所有下级角色 id */
    public static function subtreeIds(int $id): array
    {
        $parents = Role::withoutGlobalScope()->column('parent_id', 'id');
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

    /** 角色树（含继承，供选择器）。 */
    public static function tree(): array
    {
        $all = Role::withoutGlobalScope()->order('sort', 'asc')->select()->toArray();

        return self::buildTree($all, 0);
    }

    public static function read(int $id): array
    {
        $role = self::assertExists($id);
        $nodes = self::nodes($id);

        return array_merge($role, $nodes);
    }

    public static function create(array $data): int
    {
        self::assertUniqueCode((string)($data['code'] ?? ''), 0);

        $nodes = self::pullNodes($data);
        // 新增还没有归属，插入语句不需要数据权限条件
        $id = (int)Role::withoutGlobalScope()->insertGetId($data);
        self::assignNodes($id, $nodes);

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $role = self::assertExists($id);
        if ($role['code'] === 'super_admin') {
            // 超管角色保护：code 不可改
            unset($data['code']);
        }
        if (!empty($data['code'])) {
            self::assertUniqueCode((string)$data['code'], $id);
        }
        // 只有显式带了 nodes 才重设节点（不带表示「不动节点」）
        $hasNodes = array_key_exists('nodes', $data);
        $nodes    = self::pullNodes($data);

        if ($data) {
            Role::where('id', $id)->update($data);
        }
        if ($hasNodes) {
            self::assignNodes($id, $nodes);
        }
    }

    public static function delete(int $id): void
    {
        $role = self::assertExists($id);
        if ($role['code'] === 'super_admin') {
            throw new ApiException('不能删除超管角色', 422);
        }
        // 有子角色则禁止删除（避免继承链断裂）
        if (Role::withoutGlobalScope()->where('parent_id', $id)->count() > 0) {
            throw new ApiException('存在子角色，无法删除', 422);
        }
        // 软删除：进回收站；role_node / user_role 保留，恢复后授权与分配原样回来。
        // 已软删的角色在 AuthService（登录/鉴权）与 DataScope（数据范围）里都会被过滤，
        // 因此不会继续授予任何权限。
        Role::destroy($id);
    }

    /** 角色的节点（含继承标记）。 */
    public static function nodes(int $id): array
    {
        self::assertExists($id);
        $own = RoleNode::where('role_id', $id)->column('node');
        $inherited = [];
        $parent = (int)Role::withoutGlobalScope()->where('id', $id)->value('parent_id');
        $guard = 0;
        while ($parent > 0 && $guard++ < 5) {
            $inherited = array_merge($inherited, RoleNode::where('role_id', $parent)->column('node'));
            $parent = (int)Role::withoutGlobalScope()->where('id', $parent)->value('parent_id');
        }

        return [
            'own'       => array_values(array_unique($own)),
            'inherited' => array_values(array_unique($inherited)),
        ];
    }

    /**
     * 取出并剔除「权限节点」相关键。
     *
     * - `nodes`：前端勾选的节点数组；
     * - `own` / `inherited`：`read()` 回显给前端的关联数据，编辑时会被原样带回来。
     *
     * 三者都不是 sys_role 的列，透传给 insert/update 会触发 think-orm 的
     * fields_strict 校验：`fields not exists: [nodes]`。
     *
     * @param array<string,mixed> $data 引用传入，调用后这些键已被剔除
     */
    private static function pullNodes(array &$data): array
    {
        $nodes = $data['nodes'] ?? [];
        unset($data['nodes'], $data['own'], $data['inherited']);

        if (is_string($nodes)) {
            $decoded = json_decode($nodes, true);
            $nodes   = is_array($decoded) ? $decoded : [];
        }

        return is_array($nodes) ? $nodes : [];
    }

    private static function assignNodes(int $roleId, array $nodes): void
    {
        RoleNode::where('role_id', $roleId)->delete();
        foreach (array_unique(array_map('strval', $nodes)) as $node) {
            if ($node !== '') {
                RoleNode::insert(['role_id' => $roleId, 'node' => $node]);
            }
        }
    }

    private static function assertExists(int $id): array
    {
        $role = Role::withoutGlobalScope()->where('id', $id)->find();
        if (!$role) {
            throw new ApiException('角色不存在', 404);
        }

        return $role->toArray();
    }

    private static function assertUniqueCode(string $code, int $excludeId): void
    {
        if ($code === '') {
            throw new ApiException('角色标识不能为空', 422);
        }
        // 唯一索引不做软删特例：回收站里的角色仍占用标识（withTrashed 才看得到）
        $q = Role::withoutGlobalScope()->withTrashed()->where('code', $code);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                !empty($exist->delete_time)
                    ? "角色标识 {$code} 在回收站中，请先恢复或彻底删除"
                    : '角色标识已存在',
                422
            );
        }
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
