<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/** 当前登录用户上下文，挂载在 $request->user 上。 */
final class UserContext
{
    /**
     * @param array<int,string>    $roles
     * @param array<int,string>    $permissions
     * @param array<string,mixed>  $dataScope
     * @param int                  $tenantId     当前**生效**租户（超管切换后 ≠ homeTenantId）
     * @param int                  $homeTenantId 账号所属租户（`sys_user.tenant_id`，不随切换变化）
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $username,
        public readonly string $nickname = '',
        public readonly bool   $superAdmin = false,
        public readonly array  $roles = [],
        public readonly array  $permissions = [],
        public readonly array  $dataScope = [],
        public readonly string $avatar = '',
        public readonly int    $tenantId = 0,
        public readonly int    $homeTenantId = 0,
    ) {}

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function hasPermission(string $slug): bool
    {
        return $this->superAdmin || in_array($slug, $this->permissions, true);
    }

    /**
     * 是否处于**切换后的**租户（超管进入某个真实租户）。
     *
     * 前端据此显示「正在以 XX 租户身份浏览」与「返回平台」入口。
     */
    public function isTenantSwitched(): bool
    {
        return $this->tenantId !== $this->homeTenantId;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'username'           => $this->username,
            'nickname'           => $this->nickname,
            'avatar'             => $this->avatar,
            'super_admin'        => $this->superAdmin,
            'roles'              => $this->roles,
            'permissions'        => $this->permissions,
            'data_scope'         => $this->dataScope,
            'tenant_id'          => $this->tenantId,
            'home_tenant_id'     => $this->homeTenantId,
            'tenant_switched'    => $this->isTenantSwitched(),
        ];
    }
}
