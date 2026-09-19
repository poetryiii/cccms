<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use think\facade\Db;

class User extends BaseModel
{
    protected $name = 'user';

    /** 参与多租户隔离：租户内的账号自成一套（platform 租户 = 0） */
    protected $tenantScope = true;

    protected $hidden = ['password'];

    /**
     * 数据权限：sys_user 既没有 create_by 也没有 dept_id，必须显式说明。
     *   - 「仅本人」= 只看自己这个账号（id = 我），而不是「我创建的账号」；
     *   - 「本部门 / 及以下」= 所属部门（走 sys_user_dept）落在我可见的部门集合里。
     */
    protected $dataScope = [
        'owner' => 'id',
        'dept'  => [self::class, 'deptScope'],
    ];

    /** 「本部门 / 及以下」：按 sys_user_dept 关联落到用户集合 */
    public static function deptScope($query, array $deptIds): void
    {
        $userIds = $deptIds === []
            ? []
            : Db::name('user_dept')->whereIn('dept_id', $deptIds)->column('user_id');

        // fail-closed：没有可匹配的部门时不能放行任何数据
        $query->whereIn('id', $userIds ?: [0]);
    }
}
