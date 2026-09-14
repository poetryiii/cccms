<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;

/** 用户认证与权限解析（角色继承：子角色继承父角色）。 */
final class AuthService
{
    /** 根据用户 ID 构建上下文；用户不存在/被禁用/已进回收站返回 null。 */
    public static function buildContext(int $userId): ?UserContext
    {
        // 软删除的用户立即失效：禁用/删除后旧令牌下一次请求就 401
        $user = SoftDelete::apply(Db::name('user'))->where('id', $userId)->find();
        if (!$user || (int)$user['status'] !== 1) {
            return null;
        }

        $roleIds = self::effectiveRoleIds($userId);

        return new UserContext(
            id:          (int)$user['id'],
            username:    (string)$user['username'],
            nickname:    (string)$user['nickname'],
            superAdmin:  self::hasSuperAdmin($roleIds),
            roles:       self::roleCodes($roleIds),
            permissions: self::permissions($roleIds),
            avatar:      (string)($user['avatar'] ?? ''),
        );
    }

    /** 有效角色 = 直连角色 + 其所有祖先角色（子继承父，深度上限 5，防环）。 */
    public static function effectiveRoleIds(int $userId): array
    {
        $direct = array_map('intval', Db::name('user_role')->where('user_id', $userId)->column('role_id'));
        if (!$direct) {
            return [];
        }

        // 已进回收站的角色不参与继承解析
        $roleParent = SoftDelete::apply(Db::name('role'))->column('parent_id', 'id'); // id => parent_id

        $result = $direct;
        foreach ($direct as $roleId) {
            $current = $roleId;
            $guard = 0;
            while ($guard++ < 5 && isset($roleParent[$current]) && (int)$roleParent[$current] > 0) {
                $parent = (int)$roleParent[$current];
                if (in_array($parent, $result, true)) {
                    break; // 防环
                }
                $result[] = $parent;
                $current = $parent;
            }
        }

        return array_values(array_unique($result));
    }

    public static function hasSuperAdmin(array $roleIds): bool
    {
        if (!$roleIds) {
            return false;
        }
        // 软删的角色不能继续授予超管
        return SoftDelete::apply(Db::name('role'))->where('id', 'in', $roleIds)->where('code', 'super_admin')->count() > 0;
    }

    /** @return array<int,string> */
    public static function roleCodes(array $roleIds): array
    {
        if (!$roleIds) {
            return [];
        }
        return array_values(SoftDelete::apply(Db::name('role'))->where('id', 'in', $roleIds)->column('code'));
    }

    /** 有效权限节点（所有有效角色的 role_node 并集）。 */
    public static function permissions(array $roleIds): array
    {
        if (!$roleIds) {
            return [];
        }

        $nodes = array_values(array_unique(Db::name('role_node')->where('role_id', 'in', $roleIds)->column('node')));
        if (!$nodes) {
            return [];
        }

        // 菜单被删除（回收站）后其节点不再授权——否则「删了菜单」只是看不见入口，接口仍可调用。
        // 授权行刻意保留在 role_node 里，恢复菜单后即自动重新生效。
        $trashed = SoftDelete::onlyTrashed(Db::name('menu'))->column('node');
        if ($trashed) {
            $trashed = array_flip(array_map('strval', $trashed));
            $nodes   = array_values(array_filter($nodes, static fn ($node) => !isset($trashed[(string)$node])));
        }

        return $nodes;
    }
}
