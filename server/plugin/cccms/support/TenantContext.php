<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 租户上下文：把「当前请求属于哪个租户」落到查询与写入两侧。
 *
 * 与数据权限的分工（**两者是上下级关系，不是并列关系**）：
 *   - 租户是**硬边界**：跨租户的数据在任何情况下都不可见，超管也不例外
 *     （超管跨租户只能靠「切换租户」显式进入，见 `TenantLogic::switchTo()`）；
 *   - 数据档位是租户**内部**的软范围：在租户边界之内再按「本部门 / 仅本人 / 自定义规则」收窄。
 *
 * 正因如此，租户条件**不能**塞进 `DataScope::row()` —— 那个函数在超管或
 * 「全部数据」档位时直接 return，把租户条件放进去会让超管一眼看穿所有租户。
 * 正确做法是模型层独立的全局作用域（`BaseModel` 的 `tenant`），见 `scopeTenant()`。
 *
 * 生效条件：**有当前用户**（`UserContext`）。CLI（定时任务 / 命令 / 迁移）、
 * 登录链路与公共路由没有用户上下文，自动跳过 —— 平台级任务需要跨租户处理数据。
 */
final class TenantContext
{
    /**
     * 平台/默认租户 ID。
     *
     * `sys_tenant` **不占用这一行**（MySQL 自增列插入 0 会被当成「取下一个自增值」），
     * 它是虚拟租户：所有老数据、平台自身的账号与组织都落在 0。
     */
    public const PLATFORM_ID = 0;

    /** 当前请求所属租户；无用户上下文（CLI / 登录链路 / 公共路由）返回 null */
    public static function currentTenantId(): ?int
    {
        $user = DataScope::currentUser();

        return $user?->tenantId;
    }

    /**
     * 参与租户隔离的表（不含连接前缀），与「模型声明 `$tenantScope = true`」一一对应。
     *
     * 模型查询由 `BaseModel` 的全局作用域自动收敛，不需要这张名单；
     * 它只服务于**查询构造器直查**的入口（`DictLogic` / `CategoryLogic` / `RecycleLogic`
     * 等尚未模型化的模块），让那些路径也能拿到同一套租户条件。
     */
    public const TABLES = [
        'user', 'dept', 'role', 'post', 'data_rule', 'data_scope_table',
        'notice', 'file', 'category', 'dict_type', 'dict_data', 'crontab',
    ];

    /** 该表是否参与租户隔离（不含前缀的表名） */
    public static function participates(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    /**
     * 查询构造器直查入口：等价 `Db::name($table)`，额外按当前租户收敛。
     *
     * 未参与隔离的表（菜单 / 配置 / 日志 / 关联表 / 插件表）以及无用户上下文
     * （CLI / 登录链路）时原样返回，调用方不需要自己判断。
     */
    public static function table(string $table)
    {
        $query = Db::name($table);
        self::applyToQuery($query, $table);

        return $query;
    }

    /**
     * 查询侧：给**任意查询构造器**注入租户条件（`TenantContext::table()` 的底层实现）。
     *
     * @param mixed  $query 查询对象（Db::name / Db::table 的返回值）
     * @param string $table 不含前缀的表名
     */
    public static function applyToQuery($query, string $table): void
    {
        if (!self::participates($table)) {
            return;
        }

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    /**
     * 写入侧（查询构造器版）：剔除调用方传入的 `tenant_id` 并盖成当前租户。
     *
     * 模型写入由 `ScopedQuery::protect()` 兜住；直查 insert 没有这层保护，
     * 不盖租户会让新行落到 `tenant_id = 0`（默认值），创建完自己反而看不见。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function stamp(string $table, array $data): array
    {
        if (!self::participates($table)) {
            return $data;
        }

        unset($data['tenant_id']);

        $tenantId = self::currentTenantId();
        if ($tenantId !== null) {
            $data['tenant_id'] = $tenantId;
        }

        return $data;
    }

    /** 是否处于平台租户（无用户上下文时同样按平台处理，供只读展示使用） */
    public static function isPlatform(): bool
    {
        return self::currentTenantId() === self::PLATFORM_ID;
    }

    /**
     * 租户是否可用：存在、未删除、已启用、未过期。平台租户恒可用。
     *
     * 每个已鉴权请求都会走一次（`AuthService::buildContext`）：租户被停用 / 到期后，
     * 该租户下所有账号**下一次请求即 401**，无需额外拉黑令牌 —— 与「禁用用户立即生效」
     * 是同一套口径。平台租户直接返回，不产生额外查询。
     */
    public static function usable(int $tenantId): bool
    {
        if ($tenantId <= self::PLATFORM_ID) {
            return true;
        }

        try {
            $row = SoftDelete::apply(Db::name('tenant'))
                ->field(['status', 'expire_at'])
                ->where('id', $tenantId)
                ->find();
        } catch (Throwable) {
            // 表还没建（升级过程中）不让鉴权整体崩掉：按不可用处理更安全
            return false;
        }

        if (!$row || (int)$row['status'] !== 1) {
            return false;
        }

        $expire = (string)($row['expire_at'] ?? '');

        return $expire === '' || $expire > date('Y-m-d H:i:s');
    }

    /**
     * 查询侧：给模型查询注入租户条件。
     *
     * 由 `BaseModel::scopeTenant()` 调用，挂在全局作用域上，因此
     * 列表、详情、单条写操作（`where(id)->update()`）全部自动带上，
     * 不存在「列表过滤了、按 id 直调却越权」的漏网。
     *
     * @param mixed $query 模型查询对象
     */
    public static function applyToModelQuery($query, bool $participates): void
    {
        if (!$participates) {
            return;
        }

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    /**
     * 写入侧：剔除客户端传入的 `tenant_id`，并按当前租户强制写入。
     *
     * 由 `ScopedQuery::protect()` 在写库前统一调用，覆盖 insert / insertAll /
     * update / save 全部路径，业务层不需要（也不可能）自己填租户。
     *
     * 为什么**没有**「insert 才写、update 不写」的区分：
     * 更新语句已经被租户作用域收窄（跨租户的行匹配不到），所以把 tenant_id
     * 一并写入只是同值覆盖，不会把数据搬到别的租户；统一处理反而少一处分支。
     *
     * @param array<string,mixed> $data 引用传入，调用后 tenant_id 已是服务端认定值
     */
    public static function applyWriteRules(array &$data, bool $participates): void
    {
        if (!$participates) {
            return;
        }

        // 客户端不可控：先剔除，再按当前租户回填
        unset($data['tenant_id']);

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            return;
        }

        $data['tenant_id'] = $tenantId;
    }
}