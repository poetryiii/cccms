<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\app\model\UserPost;
use plugin\cccms\app\model\UserRole;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\UserContext;

/**
 * 用户管理逻辑。
 *
 * 数据权限**全部由模型层承担**，这里不再出现任何手写判定：
 *   - 行级范围：「仅本人」= id、「本部门」走 sys_user_dept → `BaseModel::scopeDataScope()`；
 *   - 出参字段规则（hidden / mask / encrypt）→ `BaseModel::toArray()`；
 *   - 入参字段剔除（不可见 / 不可改）→ `ScopedQuery` 在写库前统一处理。
 *
 * 需要看到**全量**数据的地方（唯一性、存在性判断）显式用 `User::withoutGlobalScope()`
 * 跳出作用域，否则会出现「因为看不见，所以当成没重复」这种脏数据。
 */
final class UserLogic
{
    /** 对外安全字段（不含 password）。 */
    private const SAFE_FIELDS = 'id,username,nickname,avatar,email,phone,status,remark,login_time,login_ip,create_time,update_time';

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列。
        // 软删除过滤与行级数据范围都由模型层提供，这里只切换数据源。
        $query = !empty($params['trashed']) ? User::onlyTrashed() : User::newScopedQuery();
        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        if (!empty($params['nickname'])) {
            $query->where('nickname', 'like', '%' . $params['nickname'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        // 字段级规则（hidden / mask / encrypt）已由 User::toArray() 统一处理
        $list = $query->field(self::SAFE_FIELDS)->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public static function read(int $id): array
    {
        // 数据权限已由模型作用域生效：越权时这里直接查不到
        $user = User::newScopedQuery()->where('id', $id)->field(self::SAFE_FIELDS)->find();
        if (!$user) {
            // 区分「真不存在」（404）与「存在但越权」（403）
            self::assertExists($id);
            throw new ApiException('无权查看该用户', 403);
        }

        // toArray() 里已统一应用字段级规则（hidden 不下发 / mask 掩码 / encrypt 密文），
        // 掩码值被表单回写的问题由 ScopedQuery 在写入侧剔除兜住
        $row = $user->toArray();

        $row['role_ids'] = array_map('intval', UserRole::where('user_id', $id)->column('role_id'));
        $row['dept_ids'] = array_map('intval', UserDept::where('user_id', $id)->column('dept_id'));
        $row['post_ids'] = array_map('intval', UserPost::where('user_id', $id)->column('post_id'));

        return $row;
    }

    public static function create(array $data): int
    {
        self::assertUniqueUsername((string)($data['username'] ?? ''), 0);
        if (empty($data['password'])) {
            throw new ApiException('密码不能为空', 422);
        }
        self::assertPassword((string)$data['password']);
        $data['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);

        // 字段级规则的入参剔除由 ScopedQuery 在写库前统一完成（见 app/model/ScopedQuery.php）

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');

        // 新增还没有「归属」，插入语句不需要数据权限条件
        $id = (int)User::withoutGlobalScope()->insertGetId($data);
        self::assign($id, $roleIds, $deptIds, $postIds);
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        self::assertExists($id);
        self::assertInScope($id);
        if (!empty($data['username'])) {
            self::assertUniqueUsername((string)$data['username'], $id);
        }
        if (!empty($data['password'])) {
            $data['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');

        User::newScopedQuery()->where('id', $id)->update($data);
        self::assign($id, $roleIds, $deptIds, $postIds);
    }

    public static function delete(int $id, UserContext $operator): void
    {
        if ($id === $operator->id) {
            throw new ApiException('不能删除自己', 422);
        }
        self::assertExists($id);
        self::assertInScope($id);

        $roleIds = array_map('intval', UserRole::where('user_id', $id)->column('role_id'));
        $isSuper = $roleIds !== []
            && Role::whereIn('id', $roleIds)->where('code', 'super_admin')->count() > 0;
        if ($isSuper) {
            throw new ApiException('不能删除超管账号', 422);
        }

        // 软删除：进回收站；关联表刻意保留，恢复后角色/部门/岗位原样回来。
        // destroy() 由 SoftDelete trait 提供，作用域仍然生效（越权 id 匹配不到行）
        User::destroy($id);
    }

    public static function resetPassword(int $id, string $password): void
    {
        self::assertPassword($password);
        self::assertExists($id);
        self::assertInScope($id);

        User::newScopedQuery()
            ->where('id', $id)
            ->update(['password' => password_hash($password, PASSWORD_BCRYPT)]);
    }

    /**
     * 单条记录的数据范围校验。
     *
     * 「查不到」与「不存在」在响应上要区分开：越权给 403，而不是伪装成 404。
     * 范围本身由 User 模型的全局作用域注入（与控制器传入的 `$request->user` 一致）。
     */
    private static function assertInScope(int $id): void
    {
        if (User::newScopedQuery()->where('id', $id)->count() === 0) {
            throw new ApiException('无权操作该用户', 403);
        }
    }

    /**
     * 存在性校验：必须看到**全量**数据（含其他部门、含回收站），显式跳出数据权限。
     */
    private static function assertExists(int $id): void
    {
        if (!User::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException('用户不存在', 404);
        }
    }

    /** 密码长度校验：策略见 PasswordPolicy（阈值来自 security.password_min_length） */
    private static function assertPassword(string $password): void
    {
        PasswordPolicy::assertValid($password);
    }

    private static function assign(int $userId, array $roleIds, array $deptIds, array $postIds): void
    {
        UserRole::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
            if ($rid > 0) {
                UserRole::insert(['user_id' => $userId, 'role_id' => $rid]);
            }
        }
        UserDept::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $deptIds)) as $did) {
            if ($did > 0) {
                UserDept::insert(['user_id' => $userId, 'dept_id' => $did]);
            }
        }
        UserPost::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $postIds)) as $pid) {
            if ($pid > 0) {
                UserPost::insert(['user_id' => $userId, 'post_id' => $pid]);
            }
        }
    }

    private static function assertUniqueUsername(string $username, int $excludeId): void
    {
        if ($username === '') {
            throw new ApiException('用户名不能为空', 422);
        }

        // 唯一索引不做软删特例：回收站里的用户仍占用用户名，这里给出可读提示。
        // 唯一性判断必须看全量数据，否则「看不见就当没重复」会写出脏索引；
        // withTrashed() 才能把回收站里的账号也算进来。
        $q = User::withoutGlobalScope()->withTrashed()->where('username', $username);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                !empty($exist->delete_time)
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
