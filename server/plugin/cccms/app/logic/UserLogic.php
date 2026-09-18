<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\app\model\UserPost;
use plugin\cccms\app\model\UserRole;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\Csv;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\PermissionCache;
use plugin\cccms\support\UserContext;
use Throwable;
use Webman\Http\Response;
use Webman\Http\UploadFile;

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

    /** 导出上限：避免一次导出把内存打满 */
    private const EXPORT_LIMIT = 5000;

    /** 导入模板表头（也用于校验必填列）。roles/depts/posts 为名称多值列，逗号分隔。 */
    public const IMPORT_HEADERS = ['username', 'nickname', 'email', 'phone', 'status', 'password', 'roles', 'depts', 'posts'];

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

        $roleIds = self::pull($data, 'role_ids') ?? [];
        $deptIds = self::pull($data, 'dept_ids') ?? [];
        $postIds = self::pull($data, 'post_ids') ?? [];

        // 新增还没有「归属」，插入语句不需要数据权限条件
        $id = (int)User::withoutGlobalScope()->insertGetId($data);
        self::assign($id, $roleIds, $deptIds, $postIds);
        // 角色 / 部门 / 岗位关系变化会影响该用户的权限与数据范围缓存
        PermissionCache::bump();

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

        // 只有显式提供了关联数组时才重设（未提供 = 保持原样，导入「留空不改动」依赖此语义）；
        // 提供了空数组 = 清空对应关联（表单编辑的默认行为）。
        if ($roleIds !== null) {
            self::assignRoles($id, $roleIds);
        }
        if ($deptIds !== null) {
            self::assignDepts($id, $deptIds);
        }
        if ($postIds !== null) {
            self::assignPosts($id, $postIds);
        }
        PermissionCache::bump();
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
        PermissionCache::bump();
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

    /** 导出当前数据范围内的用户（CSV，直接返回文件流） */
    public static function export(array $params): Response
    {
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

        $rows = $query->field(self::SAFE_FIELDS)->order('id', 'desc')->limit(self::EXPORT_LIMIT)->select()->toArray();
        $roleNames = self::roleNamesOf(array_map('intval', array_column($rows, 'id')));

        $data = array_map(static fn (array $row): array => [
            (string)$row['id'],
            (string)$row['username'],
            (string)$row['nickname'],
            (string)$row['email'],
            (string)$row['phone'],
            implode('、', $roleNames[(int)$row['id']] ?? []),
            (int)$row['status'] === 1 ? '启用' : '禁用',
            (string)($row['create_time'] ?? ''),
        ], $rows);

        return Csv::download('用户列表', ['ID', '用户名', '昵称', '邮箱', '手机号', '角色', '状态', '创建时间'], $data);
    }

    /** 下载导入模板 */
    public static function template(): Response
    {
        return Csv::download('用户导入模板', array_merge(self::IMPORT_HEADERS, ['说明']), [
            [
                'zhangsan', '张三', 'zhangsan@example.com', '13800000000', '1', '初始密码(至少6位)',
                '员工', '研发部', '工程师',
                '已存在的用户名会被更新；新用户必须填 password；roles/depts/posts 按名称匹配、多值用逗号分隔、更新时留空则不改动',
            ],
        ]);
    }

    /**
     * 导入用户（CSV）。
     *
     * 约定：
     *   - `username` 必填；已存在则**更新**（走 update，受数据范围约束），不存在则**新增**；
     *   - 新增必须提供 `password`（不内置弱口令默认值，避免埋雷）；
     *   - 单行失败不影响其余行，失败原因按行号汇总返回。
     *
     * @return array{total:int,created:int,updated:int,failed:array<int,string>}
     */
    public static function import(UploadFile $file): array
    {
        $parsed  = Csv::parse($file);
        $headers = $parsed['headers'];

        if (!in_array('username', $headers, true)) {
            throw new ApiException('CSV 缺少 username 列，请先下载导入模板', 422);
        }

        // 名称 → id 映射一次性预载，避免逐行查库（N+1）。
        // 显式跳出数据权限：导入是系统配置动作，必须看全量角色/部门/岗位，
        // 否则「看不见的角色」会被误判为不存在。
        $roleNameMap = Role::withoutGlobalScope()->column('id', 'name');
        $roleCodeMap = Role::withoutGlobalScope()->column('id', 'code');
        $deptNameMap = Dept::withoutGlobalScope()->column('id', 'name');
        $postNameMap = Post::withoutGlobalScope()->column('id', 'name');
        $postCodeMap = Post::withoutGlobalScope()->column('id', 'code');

        $created = 0;
        $updated = 0;
        $failed  = [];

        foreach ($parsed['rows'] as $index => $row) {
            $line     = $index + 2;   // 第 1 行是表头
            $username = trim((string)($row['username'] ?? ''));

            if ($username === '') {
                $failed[] = "第 {$line} 行：用户名为空";
                continue;
            }

            try {
                // 名称 → id；找不到即行级失败，避免静默丢掉授权
                $roleIds = self::resolveIds((string)($row['roles'] ?? ''), $roleNameMap, $roleCodeMap, '角色');
                $deptIds = self::resolveIds((string)($row['depts'] ?? ''), $deptNameMap, null, '部门');
                $postIds = self::resolveIds((string)($row['posts'] ?? ''), $postNameMap, $postCodeMap, '岗位');

                // 唯一性判断要看全量（含回收站），否则会撞唯一键
                $exist = User::withoutGlobalScope()->withTrashed()->where('username', $username)->find();

                $data = [
                    'nickname' => (string)($row['nickname'] ?? ''),
                    'email'    => (string)($row['email'] ?? ''),
                    'phone'    => (string)($row['phone'] ?? ''),
                    'status'   => self::parseStatus((string)($row['status'] ?? '1')),
                ];

                if ($exist) {
                    // 更新：只有填了 roles/depts/posts 才覆盖对应关联（留空 = 保持原样）
                    if (trim((string)($row['roles'] ?? '')) !== '') {
                        $data['role_ids'] = $roleIds;
                    }
                    if (trim((string)($row['depts'] ?? '')) !== '') {
                        $data['dept_ids'] = $deptIds;
                    }
                    if (trim((string)($row['posts'] ?? '')) !== '') {
                        $data['post_ids'] = $postIds;
                    }
                    self::update((int)$exist['id'], $data);
                    $updated++;
                    continue;
                }

                $password = (string)($row['password'] ?? '');
                if ($password === '') {
                    throw new ApiException('缺少初始密码', 422);
                }
                self::create($data + [
                    'username' => $username,
                    'password' => $password,
                    'role_ids' => $roleIds,
                    'dept_ids' => $deptIds,
                    'post_ids' => $postIds,
                ]);
                $created++;
            } catch (Throwable $e) {
                $failed[] = "第 {$line} 行：" . $e->getMessage();
            }
        }

        return [
            'total'   => count($parsed['rows']),
            'created' => $created,
            'updated' => $updated,
            'failed'  => $failed,
        ];
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
        self::assignRoles($userId, $roleIds);
        self::assignDepts($userId, $deptIds);
        self::assignPosts($userId, $postIds);
    }

    private static function assignRoles(int $userId, array $roleIds): void
    {
        UserRole::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
            if ($rid > 0) {
                UserRole::insert(['user_id' => $userId, 'role_id' => $rid]);
            }
        }
    }

    private static function assignDepts(int $userId, array $deptIds): void
    {
        UserDept::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $deptIds)) as $did) {
            if ($did > 0) {
                UserDept::insert(['user_id' => $userId, 'dept_id' => $did]);
            }
        }
    }

    private static function assignPosts(int $userId, array $postIds): void
    {
        UserPost::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $postIds)) as $pid) {
            if ($pid > 0) {
                UserPost::insert(['user_id' => $userId, 'post_id' => $pid]);
            }
        }
    }

    /**
     * 把「名称（英文逗号 / 中文逗号 / 顿号 / 分号分隔）」解析成 id 列表。
     * 先按 name 精确匹配，可选按 code 兜底；找不到即抛异常（行级失败，不静默丢授权）。
     *
     * @param array<string,int>      $nameMap 名称 → id
     * @param array<string,int>|null $codeMap 编码 → id（角色 / 岗位有 code，部门没有）
     * @return array<int,int>
     */
    private static function resolveIds(string $raw, array $nameMap, ?array $codeMap, string $label): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $tokens = preg_split('/[,，、;；]/u', $raw) ?: [];
        $out    = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $id = $nameMap[$token] ?? null;
            if ($id === null && $codeMap !== null) {
                $id = $codeMap[$token] ?? null;
            }
            if ($id === null) {
                throw new ApiException("{$label}「{$token}」不存在", 422);
            }
            $out[] = (int)$id;
        }

        return array_values(array_unique($out));
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

    /**
     * 取出并移除关联数组字段。
     *
     * @return array<int,mixed>|null null = 键未提供（更新时表示「不改动」）；数组 = 已提供（空数组 = 清空）
     */
    private static function pull(array &$data, string $key): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        unset($data[$key]);
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * 批量取「用户 => 角色名列表」，避免导出时逐行查库（N+1）。
     *
     * @param  array<int,int> $userIds
     * @return array<int,array<int,string>>
     */
    private static function roleNamesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $pairs   = UserRole::whereIn('user_id', $userIds)->select()->toArray();
        $roleIds = array_values(array_unique(array_map('intval', array_column($pairs, 'role_id'))));
        $names   = $roleIds === [] ? [] : Role::whereIn('id', $roleIds)->column('name', 'id');

        $out = [];
        foreach ($pairs as $pair) {
            $out[(int)$pair['user_id']][] = (string)($names[(int)$pair['role_id']] ?? '');
        }

        return $out;
    }

    /** 导入时的状态取值容错：1/0、启用/禁用、是/否、true/false */
    private static function parseStatus(string $value): int
    {
        $value = strtolower(trim($value));

        if (in_array($value, ['0', '禁用', '否', 'false', 'no', 'off'], true)) {
            return 0;
        }

        return 1;
    }
}
