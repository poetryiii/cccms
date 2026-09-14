<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\DataScope;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\SoftDelete;
use plugin\cccms\support\UserContext;
use think\facade\Db;

/** 用户管理逻辑。 */
final class UserLogic
{
    /** 对外安全字段（不含 password）。 */
    private const SAFE_FIELDS = 'id,username,nickname,avatar,email,phone,status,remark,login_time,login_ip,create_time,update_time';

    /** 本模块对应的表（不含前缀），用于只取「不限表」或「指定了本表」的数据权限规则 */
    private const TABLE = 'user';

    public static function paginate(array $params, UserContext $user): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列
        $query = SoftDelete::listQuery('user', $params);
        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        if (!empty($params['nickname'])) {
            $query->where('nickname', 'like', '%' . $params['nickname'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        self::applyDataScope($query, $user);

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->field(self::SAFE_FIELDS)->page($page, $limit)->order('id', 'desc')->select()->toArray();

        // 字段级规则（hidden / mask / encrypt）作用在出参上
        DataScope::field($list, $user, self::TABLE);

        return ['total' => $total, 'list' => $list];
    }

    public static function read(int $id, UserContext $operator): array
    {
        $user = SoftDelete::apply(Db::name('user'))->where('id', $id)->field(self::SAFE_FIELDS)->find();
        if (!$user) {
            throw new ApiException('用户不存在', 404);
        }
        self::assertInScope($id, $operator);

        $user['role_ids']  = array_map('intval', Db::name('user_role')->where('user_id', $id)->column('role_id'));
        $user['dept_ids']  = array_map('intval', Db::name('user_dept')->where('user_id', $id)->column('dept_id'));
        $user['post_ids']  = array_map('intval', Db::name('user_post')->where('user_id', $id)->column('post_id'));
        return $user;
    }

    public static function create(array $data, UserContext $operator): int
    {
        self::assertUniqueUsername((string)($data['username'] ?? ''), 0);
        if (empty($data['password'])) {
            throw new ApiException('密码不能为空', 422);
        }
        self::assertPassword((string)$data['password']);
        $data['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);

        // readonly 字段级规则：强制剔除，防止前端提交越权修改
        DataScope::stripReadonly($data, $operator, self::TABLE);

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');

        $id = (int)Db::name('user')->insertGetId($data);
        self::assign($id, $roleIds, $deptIds, $postIds);
        return $id;
    }

    public static function update(int $id, array $data, UserContext $operator): void
    {
        self::assertExists($id);
        self::assertInScope($id, $operator);
        if (!empty($data['username'])) {
            self::assertUniqueUsername((string)$data['username'], $id);
        }
        if (!empty($data['password'])) {
            $data['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        DataScope::stripReadonly($data, $operator, self::TABLE);

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');

        Db::name('user')->where('id', $id)->update($data);
        self::assign($id, $roleIds, $deptIds, $postIds);
    }

    public static function delete(int $id, UserContext $operator): void
    {
        if ($id === $operator->id) {
            throw new ApiException('不能删除自己', 422);
        }
        self::assertExists($id);
        self::assertInScope($id, $operator);
        $isSuper = Db::name('user_role')
            ->alias('ur')
            ->join('role r', 'r.id = ur.role_id')
            ->where('ur.user_id', $id)
            ->where('r.code', 'super_admin')
            ->count();
        if ($isSuper > 0) {
            throw new ApiException('不能删除超管账号', 422);
        }
        // 软删除：进回收站；关联表刻意保留，恢复后角色/部门/岗位原样回来
        SoftDelete::remove(Db::name('user'), $id);
    }

    public static function resetPassword(int $id, string $password, UserContext $operator): void
    {
        self::assertPassword($password);
        self::assertExists($id);
        self::assertInScope($id, $operator);
        Db::name('user')->where('id', $id)->update(['password' => password_hash($password, PASSWORD_BCRYPT)]);
    }

    /**
     * 用户表的数据范围适配。
     *
     * sys_user 既没有 create_by 也没有 dept_id，所以要显式说明：
     *   - 「仅本人」= 只看自己这个账号（id = 我），而不是「我创建的账号」
     *   - 「本部门 / 及以下」= 所属部门（走 sys_user_dept）落在我可见的部门集合里
     */
    private static function applyDataScope($query, UserContext $user): void
    {
        DataScope::row($query, $user, [
            'owner' => 'id',
            'table' => self::TABLE,
            'dept'  => static function ($q, array $deptIds): void {
                $userIds = $deptIds === []
                    ? []
                    : Db::name('user_dept')->whereIn('dept_id', $deptIds)->column('user_id');
                // fail-closed：没有可匹配的部门时不能放行任何数据
                $q->whereIn('id', $userIds ?: [0]);
            },
        ]);
    }

    /**
     * 单条记录的数据范围校验。
     *
     * 只过滤列表是不够的：详情 / 编辑 / 删除 / 重置密码都能按 id 直接命中，
     * 必须在单条入口再校验一次，否则越权只是「看不见」而不是「做不到」。
     */
    private static function assertInScope(int $id, UserContext $user): void
    {
        $query = SoftDelete::apply(Db::name('user'))->where('id', $id);
        self::applyDataScope($query, $user);
        if ($query->count() === 0) {
            throw new ApiException('无权操作该用户', 403);
        }
    }

    /** 密码长度校验：策略见 PasswordPolicy（阈值来自 security.password_min_length） */
    private static function assertPassword(string $password): void
    {
        PasswordPolicy::assertValid($password);
    }

    private static function assign(int $userId, array $roleIds, array $deptIds, array $postIds): void
    {
        Db::name('user_role')->where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
            if ($rid > 0) {
                Db::name('user_role')->insert(['user_id' => $userId, 'role_id' => $rid]);
            }
        }
        Db::name('user_dept')->where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $deptIds)) as $did) {
            if ($did > 0) {
                Db::name('user_dept')->insert(['user_id' => $userId, 'dept_id' => $did]);
            }
        }
        Db::name('user_post')->where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $postIds)) as $pid) {
            if ($pid > 0) {
                Db::name('user_post')->insert(['user_id' => $userId, 'post_id' => $pid]);
            }
        }
    }

    private static function assertExists(int $id): void
    {
        if (!SoftDelete::apply(Db::name('user'))->where('id', $id)->find()) {
            throw new ApiException('用户不存在', 404);
        }
    }

    private static function assertUniqueUsername(string $username, int $excludeId): void
    {
        if ($username === '') {
            throw new ApiException('用户名不能为空', 422);
        }
        // 唯一索引不做软删特例：回收站里的用户仍占用用户名，这里给出可读提示
        $q = Db::name('user')->where('username', $username);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                SoftDelete::isTrashed($exist)
                    ? "账号 {$username} 在回收站中，请先恢复或彻底删除"
                    : '用户名已存在',
                422
            );
        }
    }

    private static function pull(array &$data, string $key): array
    {
        $value = $data[$key] ?? [];
        unset($data[$key]);
        return is_array($value) ? $value : [];
    }
}
