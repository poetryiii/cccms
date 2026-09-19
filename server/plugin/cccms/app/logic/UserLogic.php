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
use plugin\cccms\support\I18n;
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
 * 需要看到**全量**数据的地方（存在性判断）显式用 `User::withoutGlobalScope()`
 * —— 它保留租户边界、只跳出数据权限；而 `username` 是**全局唯一索引**，
 * 这类唯一性校验必须用 `User::withoutAllScopes()` 连租户一起绕过，否则会漏判并写出脏索引。
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
            throw new ApiException(I18n::t('user.no_permission_view'), 403);
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
            throw new ApiException(I18n::t('user.password_required'), 422);
        }
        self::assertPassword((string)$data['password'], ['username' => (string)($data['username'] ?? '')]);
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
            // 编辑用户同样会改密码，必须过同一套策略，否则这里就是绕过策略的缺口
            self::assertPassword((string)$data['password'], self::identityOf($id, $data));
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
            throw new ApiException(I18n::t('user.cannot_delete_self'), 422);
        }
        self::assertExists($id);
        self::assertInScope($id);

        $roleIds = array_map('intval', UserRole::where('user_id', $id)->column('role_id'));
        $isSuper = $roleIds !== []
            && Role::whereIn('id', $roleIds)->where('code', 'super_admin')->count() > 0;
        if ($isSuper) {
            throw new ApiException(I18n::t('user.cannot_delete_super'), 422);
        }

        // 软删除：进回收站；关联表刻意保留，恢复后角色/部门/岗位原样回来。
        // destroy() 由 SoftDelete trait 提供，作用域仍然生效（越权 id 匹配不到行）
        User::destroy($id);
        PermissionCache::bump();
    }

    public static function resetPassword(int $id, string $password): void
    {
        self::assertPassword($password, self::identityOf($id));
        self::assertExists($id);
        self::assertInScope($id);

        User::newScopedQuery()
            ->where('id', $id)
            ->update(['password' => password_hash($password, PASSWORD_BCRYPT)]);
    }

    // ---- 批量操作 ----
    // 统一语义：**只作用于当前数据范围内的行**，范围外的 id 被跳过并回报，
    // 不抛异常整批失败 —— 管理员选了 10 条，其中 1 条越权，另 9 条仍应生效。

    /**
     * 批量启用 / 禁用。
     *
     * 禁用会让对方**下一次请求**即 401（`AuthService::buildContext()` 每请求实时查状态），
     * 无需额外拉黑令牌。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchStatus(array $ids, int $status, UserContext $operator): array
    {
        $ids = self::batchIds($ids);
        $inScope = self::scopedIds($ids);

        // 自己与超管账号不参与批量禁用：与单条删除同一套保护，避免误操作把自己锁在门外
        if ($status !== 1) {
            $inScope = array_values(array_diff($inScope, self::protectedIds($inScope, $operator)));
        }

        $affected = $inScope === [] ? 0 : User::newScopedQuery()->whereIn('id', $inScope)->update(['status' => $status]);
        if ($affected > 0) {
            // 状态影响鉴权（禁用立即生效），缓存要跟着失效
            PermissionCache::bump();
        }

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $inScope))];
    }

    /**
     * 批量删除（软删）。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchDelete(array $ids, UserContext $operator): array
    {
        $ids = self::batchIds($ids);
        $inScope = array_values(array_diff(self::scopedIds($ids), self::protectedIds($ids, $operator)));
        $skipped = array_values(array_diff($ids, $inScope));

        if ($inScope === []) {
            return ['affected' => 0, 'skipped' => $skipped];
        }

        // 作用域仍然生效（越权 id 匹配不到行）；关联表刻意保留，恢复后角色/部门/岗位原样回来
        $affected = User::destroy($inScope);
        PermissionCache::bump();

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /**
     * 批量分配角色 / 部门 / 岗位。
     *
     * 语义是**整体替换**（与单条编辑表单一致）：传了哪个键就重设哪个关联，未传的保持原样。
     * 传空数组 = 清空该关联。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchAssign(array $ids, array $data): array
    {
        $ids = self::batchIds($ids);
        $inScope = self::scopedIds($ids);
        $skipped = array_values(array_diff($ids, $inScope));

        if ($inScope === []) {
            return ['affected' => 0, 'skipped' => $skipped];
        }

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');
        if ($roleIds === null && $deptIds === null && $postIds === null) {
            throw new ApiException(I18n::t('user.assign_target_required'), 422);
        }

        foreach ($inScope as $id) {
            if ($roleIds !== null) {
                self::assignRoles($id, $roleIds);
            }
            if ($deptIds !== null) {
                self::assignDepts($id, $deptIds);
            }
            if ($postIds !== null) {
                self::assignPosts($id, $postIds);
            }
        }

        // 角色 / 部门 / 岗位关系变化会影响数据范围与权限缓存
        PermissionCache::bump();

        return ['affected' => count($inScope), 'skipped' => $skipped];
    }

    /**
     * 当前数据范围内实际可见的 id（越权与不存在的 id 都会落选）。
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function scopedIds(array $ids): array
    {
        return array_map('intval', User::newScopedQuery()->whereIn('id', $ids)->column('id'));
    }

    /**
     * 从给定 id 中挑出「受保护、不允许批量禁用 / 删除」的那些（自己 + 超管）。
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function protectedIds(array $ids, UserContext $operator): array
    {
        if ($ids === []) {
            return [];
        }

        $superRoleIds = Role::withoutGlobalScope()->where('code', 'super_admin')->column('id');
        $superIds = $superRoleIds === []
            ? []
            : array_map('intval', UserRole::whereIn('role_id', $superRoleIds)->whereIn('user_id', $ids)->column('user_id'));

        $protected = $superIds;
        if (in_array($operator->id, $ids, true)) {
            $protected[] = $operator->id;
        }

        return array_values(array_unique($protected));
    }

    /** 规范化批量 id：去重、去非正整数 */
    private static function batchIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new ApiException(I18n::t('common.select_required'), 422);
        }

        return $ids;
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
            (int)$row['status'] === 1 ? I18n::t('user.status_enabled') : I18n::t('user.status_disabled'),
            (string)($row['create_time'] ?? ''),
        ], $rows);

        return Csv::download(I18n::t('user.export_title'), [
            'ID',
            I18n::t('user.col_username'),
            I18n::t('user.col_nickname'),
            I18n::t('user.col_email'),
            I18n::t('user.col_phone'),
            I18n::t('user.label_role'),
            I18n::t('user.col_status'),
            I18n::t('user.col_create_time'),
        ], $data);
    }

    /** 下载导入模板 */
    public static function template(): Response
    {
        return Csv::download(I18n::t('user.template_title'), array_merge(self::IMPORT_HEADERS, [I18n::t('user.col_remark')]), [
            [
                'zhangsan',
                I18n::t('user.template_sample_nickname'),
                'zhangsan@example.com',
                '13800000000',
                '1',
                I18n::t('user.template_pw_hint'),
                I18n::t('user.template_sample_role'),
                I18n::t('user.template_sample_dept'),
                I18n::t('user.template_sample_post'),
                I18n::t('user.template_hint'),
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
            throw new ApiException(I18n::t('user.csv_missing_username'), 422);
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
                $failed[] = I18n::t('user.import_line_prefix', ['line' => $line]) . I18n::t('user.import_username_empty');
                continue;
            }

            try {
                // 名称 → id；找不到即行级失败，避免静默丢掉授权
                $roleIds = self::resolveIds((string)($row['roles'] ?? ''), $roleNameMap, $roleCodeMap, I18n::t('user.label_role'));
                $deptIds = self::resolveIds((string)($row['depts'] ?? ''), $deptNameMap, null, I18n::t('user.label_dept'));
                $postIds = self::resolveIds((string)($row['posts'] ?? ''), $postNameMap, $postCodeMap, I18n::t('user.label_post'));

                // 唯一性判断要看全量（含回收站）：uk_username 是全局唯一索引，需完全绕过租户作用域
                $exist = User::withoutAllScopes()->withTrashed()->where('username', $username)->find();

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
                    throw new ApiException(I18n::t('user.initial_password_required'), 422);
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
                $failed[] = I18n::t('user.import_line_prefix', ['line' => $line]) . $e->getMessage();
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
            throw new ApiException(I18n::t('user.no_permission_operate'), 403);
        }
    }

    /**
     * 存在性校验：必须看到**全量**数据（含其他部门、含回收站），显式跳出数据权限。
     */
    private static function assertExists(int $id): void
    {
        if (!User::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException(I18n::t('user.not_found'), 404);
        }
    }

    /**
     * 密码强度校验：策略见 PasswordPolicy。
     *
     * 传入身份信息是为了拒绝「密码包含用户名/昵称/邮箱」这类可猜口令；
     * 调用点能拿到多少就传多少（重置密码时只有用户 id，需从库里补身份）。
     *
     * @param array<string,mixed> $identity
     */
    private static function assertPassword(string $password, array $identity = []): void
    {
        PasswordPolicy::assertValid($password, $identity);
    }

    /**
     * 组装密码策略所需的身份信息（用户名 / 昵称 / 邮箱）。
     *
     * 表单提交的改动尚未落库，所以**入参优先**；入参没带的字段再用库里的存量值补齐
     * （重置密码接口只传 id，全靠这一步）。查库失败时不阻断，退化为「只校验纯口令强度」。
     *
     * @param array<string,mixed> $data 待写入的数据（优先取其中的身份字段）
     * @return array<string,string>
     */
    private static function identityOf(int $id, array $data = []): array
    {
        $identity = [
            'username' => trim((string)($data['username'] ?? '')),
            'nickname' => trim((string)($data['nickname'] ?? '')),
            'email'    => trim((string)($data['email'] ?? '')),
        ];

        if ($id <= 0 || ($identity['username'] !== '' && $identity['nickname'] !== '' && $identity['email'] !== '')) {
            return $identity;
        }

        try {
            $row = User::withoutGlobalScope()
                ->field(['username', 'nickname', 'email'])
                ->where('id', $id)
                ->find();
        } catch (Throwable) {
            return $identity;
        }

        foreach (['username', 'nickname', 'email'] as $key) {
            if ($identity[$key] === '') {
                $identity[$key] = trim((string)($row[$key] ?? ''));
            }
        }

        return $identity;
    }

    private static function assign(int $userId, array $roleIds, array $deptIds, array $postIds): void
    {
        self::assignRoles($userId, $roleIds);
        self::assignDepts($userId, $deptIds);
        self::assignPosts($userId, $postIds);
    }

    private static function assignRoles(int $userId, array $roleIds): void
    {
        self::assertOwnedInTenant(Role::class, $roleIds, 'user.label_role');
        UserRole::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
            if ($rid > 0) {
                UserRole::insert(['user_id' => $userId, 'role_id' => $rid]);
            }
        }
    }

    private static function assignDepts(int $userId, array $deptIds): void
    {
        self::assertOwnedInTenant(Dept::class, $deptIds, 'user.label_dept');
        UserDept::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $deptIds)) as $did) {
            if ($did > 0) {
                UserDept::insert(['user_id' => $userId, 'dept_id' => $did]);
            }
        }
    }

    private static function assignPosts(int $userId, array $postIds): void
    {
        self::assertOwnedInTenant(Post::class, $postIds, 'user.label_post');
        UserPost::where('user_id', $userId)->delete();
        foreach (array_unique(array_map('intval', $postIds)) as $pid) {
            if ($pid > 0) {
                UserPost::insert(['user_id' => $userId, 'post_id' => $pid]);
            }
        }
    }

    /**
     * 校验被引用的角色 / 部门 / 岗位都存在于**当前租户**内。
     *
     * 关联表（sys_user_role / sys_user_dept / sys_user_post）是平台级的，没有 tenant_id 列，
     * 无法靠列隔离，因此必须在写关联前挡住跨租户引用 ——
     * 否则 A 租户的管理员只要猜到 id，就能把 B 租户的角色挂到自己人身上（提权）。
     *
     * 查询走 `newScopedQuery()`：带租户作用域，跨租户的 id 自然查不到。
     *
     * @param class-string $model
     * @param array<int,mixed> $ids
     */
    private static function assertOwnedInTenant(string $model, array $ids, string $labelKey): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return;
        }

        $found = array_map('intval', $model::newScopedQuery()->whereIn('id', $ids)->column('id'));
        if (count($found) !== count($ids)) {
            throw new ApiException(I18n::t('user.ref_not_in_tenant', ['label' => I18n::t($labelKey)]), 422);
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
                throw new ApiException(I18n::t('user.token_not_found', ['label' => $label, 'token' => $token]), 422);
            }
            $out[] = (int)$id;
        }

        return array_values(array_unique($out));
    }

    private static function assertUniqueUsername(string $username, int $excludeId): void
    {
        if ($username === '') {
            throw new ApiException(I18n::t('user.username_required'), 422);
        }

        // 唯一索引不做软删特例：回收站里的用户仍占用用户名，这里给出可读提示。
        // uk_username 是**全局唯一索引**（用户名不随租户重复），必须完全绕过租户作用域，
        // 否则「别的租户已占用」会被漏判，插入时撞唯一键；
        // withTrashed() 才能把回收站里的账号也算进来。
        $q = User::withoutAllScopes()->withTrashed()->where('username', $username);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                !empty($exist->delete_time)
                    ? I18n::t('user.username_in_trash', ['username' => $username])
                    : I18n::t('user.username_exists'),
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
