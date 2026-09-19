<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use plugin\cccms\support\DataScope;
use plugin\cccms\support\UserContext;

class Dept extends BaseModel
{
    protected $name = 'dept';

    /** 参与多租户隔离：每个租户维护自己的组织架构 */
    protected $tenantScope = true;

    /**
     * 数据权限：部门自身即部门维度。
     *   - 「仅本人」= 只看我所属的部门（不含下级）；
     *   - 「本部门 / 及以下」= 只看我所属部门及其子树。
     *
     * 注意：sys_dept **不登记受控表**（不能给它配自定义规则），
     * 这里生效的只是预设基线 —— 部门管理页按「本部门及以下」收窄。
     */
    protected $dataScope = [
        'owner' => [self::class, 'ownerScope'],
        'dept'  => [self::class, 'deptScope'],
    ];

    /** 「仅本人」：我所属的部门（不含下级） */
    public static function ownerScope($query, UserContext $user): void
    {
        $ids = DataScope::myDeptIds($user);

        $query->whereIn('id', $ids ?: [0]);
    }

    /** 「本部门 / 及以下」：我所属部门及其子树 */
    public static function deptScope($query, array $deptIds): void
    {
        $query->whereIn('id', $deptIds ?: [0]);
    }
}
