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
     * 从 JWT claims 构建（骨架阶段用 claims 携带权限快照；
     * todo 6 改为从 Redis/DB 实时加载权限集合）。
     *
     * @param array<string,mixed> $claims
     */
    public static function fromClaims(array $claims): self
    {
        return new self(
            id:          (int)($claims['uid'] ?? $claims['sub'] ?? 0),
            username:    (string)($claims['username'] ?? ''),
            nickname:    (string)($claims['nickname'] ?? ''),
            superAdmin:  (bool)($claims['super_admin'] ?? false),
            roles:       (array)($claims['roles'] ?? []),
            permissions: (array)($claims['permissions'] ?? []),
            dataScope:   (array)($claims['data_scope'] ?? []),
            avatar:      (string)($claims['avatar'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'username'    => $this->username,
            'nickname'    => $this->nickname,
            'avatar'      => $this->avatar,
            'super_admin' => $this->superAdmin,
            'roles'       => $this->roles,
            'permissions' => $this->permissions,
            'data_scope'  => $this->dataScope,
        ];
    }
}
