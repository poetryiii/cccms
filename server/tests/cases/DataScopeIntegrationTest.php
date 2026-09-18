<?php

declare(strict_types=1);

use plugin\cccms\support\DataScope;
use plugin\cccms\support\UserContext;
use think\facade\Db;

/**
 * 数据权限（三档基线）集成测试。
 *
 * 直接驱动 `DataScope::row()` 与 `myDeptIds()` / `myDeptSubtreeIds()`，配以真实库里的
 * 角色档位与部门树，验证「仅本人 / 本部门 / 本部门及以下」三档基线分别落到哪一列、
 * 以及部门子树是否按「含下级」展开。自定义档（无规则）与全部 / 超管的旁路也一并覆盖。
 *
 * 需要数据库；不可用时自动跳过（见 tests/run.php）。
 */
return static function (): void {
    suite('数据权限（三档基线）');

    $seedRole = static fn (string $code, int $dataScope): int => (int)Db::name('role')->insertGetId([
        'name' => $code, 'code' => $code, 'data_scope' => $dataScope,
        'parent_id' => 0, 'sort' => 0, 'status' => 1, 'remark' => 'integration test',
        'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);

    $seedDept = static fn (string $name, int $parentId): int => (int)Db::name('dept')->insertGetId([
        'name' => $name, 'parent_id' => $parentId, 'sort' => 0, 'status' => 1,
        'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);

    $seedUser = static fn (string $username): int => (int)Db::name('user')->insertGetId([
        'username' => $username, 'password' => password_hash('test123', PASSWORD_BCRYPT),
        'nickname' => $username, 'status' => 1,
        'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);

    $cleanup = static function (): void {
        $userIds = Db::name('user')->where('username', 'like', '__itest__%')->column('id');
        if ($userIds) {
            Db::name('user_role')->whereIn('user_id', $userIds)->delete();
            Db::name('user_dept')->whereIn('user_id', $userIds)->delete();
            Db::name('user_post')->whereIn('user_id', $userIds)->delete();
            Db::name('user')->whereIn('id', $userIds)->delete();
        }
        Db::name('role')->where('code', 'like', '__itest__%')->delete();
        Db::name('dept')->where('name', 'like', '__itest__%')->delete();
    };

    /** 造一个「绑定某档角色 + 归属 parent 部门」的用户，返回 [userId, parentDeptId, childDeptId] */
    $seedScopedUser = static function (int $dataScope, string $suffix) use ($seedRole, $seedDept, $seedUser): array {
        $roleId     = $seedRole('__itest__role_' . $suffix, $dataScope);
        $parentDept = $seedDept('__itest__dept_p_' . $suffix, 0);
        $childDept  = $seedDept('__itest__dept_c_' . $suffix, $parentDept);
        $userId     = $seedUser('__itest__u_' . $suffix);
        Db::name('user_role')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        Db::name('user_dept')->insert(['user_id' => $userId, 'dept_id' => $parentDept]);

        return [$userId, $parentDept, $childDept];
    };

    /** 对指定用户跑一次 row() 并返回生成的 SQL（不真正执行） */
    $sqlFor = static function (int $userId, array $options): string {
        $user  = new UserContext($userId, 'itest', superAdmin: false);
        $query = Db::name('file');
        DataScope::row($query, $user, $options);

        return $query->buildSql(false);
    };

    test('仅本人 → 基线落在 create_by', function () use ($seedScopedUser, $sqlFor, $cleanup): void {
        try {
            [$userId] = $seedScopedUser(4, 'self');
            $sql = $sqlFor($userId, ['owner' => 'create_by', 'table' => 'file']);
            contains('create_by', $sql, '仅本人应命中 create_by');
            ok(!str_contains($sql, 'dept_id'), '仅本人不该出现 dept_id');
        } finally {
            $cleanup();
        }
    }, true);

    test('本部门 → 基线落在 dept_id 且不含下级', function () use ($seedScopedUser, $sqlFor, $cleanup): void {
        try {
            [$userId, $parentDept, $childDept] = $seedScopedUser(3, 'dept');
            $user = new UserContext($userId, 'itest', superAdmin: false);

            // 本部门（不含下级）：只含 parent，不含 child
            $deptIds = DataScope::myDeptIds($user);
            ok(in_array($parentDept, $deptIds, true), '本部门应包含直属部门');
            ok(!in_array($childDept, $deptIds, true), '本部门不应包含下级');

            $sql = $sqlFor($userId, ['table' => 'file']);
            contains('dept_id', $sql, '本部门应命中 dept_id');
            ok(!str_contains($sql, 'create_by'), '本部门不该出现 create_by');
        } finally {
            $cleanup();
        }
    }, true);

    test('本部门及以下 → 部门子树展开到下级', function () use ($seedScopedUser, $sqlFor, $cleanup): void {
        try {
            [$userId, $parentDept, $childDept] = $seedScopedUser(2, 'tree');
            $user = new UserContext($userId, 'itest', superAdmin: false);

            $subtree = DataScope::myDeptSubtreeIds($user);
            ok(in_array($parentDept, $subtree, true), '子树应包含直属部门');
            ok(in_array($childDept, $subtree, true), '子树应包含下级部门');

            $sql = $sqlFor($userId, ['table' => 'file']);
            contains('dept_id', $sql, '本部门及以下应命中 dept_id');
        } finally {
            $cleanup();
        }
    }, true);

    test('全部数据 / 超管 → 不加任何基线条件', function () use ($seedScopedUser, $sqlFor, $cleanup): void {
        try {
            [$userId] = $seedScopedUser(1, 'all');
            $sql = $sqlFor($userId, ['owner' => 'create_by', 'table' => 'file']);
            ok(!str_contains($sql, 'create_by') && !str_contains($sql, 'dept_id'), '全部数据不应加基线 where');

            // 超管直接旁路
            $query = Db::name('file');
            DataScope::row($query, new UserContext(1, 'admin', superAdmin: true), ['owner' => 'create_by', 'table' => 'file']);
            $superSql = $query->buildSql(false);
            ok(!str_contains($superSql, 'create_by') && !str_contains($superSql, 'dept_id'), '超管不应加基线 where');
        } finally {
            $cleanup();
        }
    }, true);

    test('自定义档且无规则 → fail-closed（1 = 0）', function () use ($seedScopedUser, $sqlFor, $cleanup): void {
        try {
            [$userId] = $seedScopedUser(5, 'custom');
            $sql = $sqlFor($userId, ['table' => 'file']);
            contains('1 = 0', $sql, '自定义档无规则应 fail-closed');
        } finally {
            $cleanup();
        }
    }, true);
};
