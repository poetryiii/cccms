<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use think\facade\Db;

class File extends BaseModel
{
    protected $name = 'file';

    /** 参与多租户隔离：附件按租户各自存放与检索 */
    protected $tenantScope = true;

    /** sys_file 只有 create_time，没有 update_time（否则模型写入会拼出不存在的列） */
    protected $updateTime = false;

    /**
     * 数据权限：
     *   - 「仅本人」= 我上传的附件（create_by，框架默认值，显式写出来便于读）；
     *   - 「本部门 / 及以下」= 可见部门成员上传的附件（create_by 落在成员集合里）。
     */
    protected $dataScope = [
        'owner' => 'create_by',
        'dept'  => [self::class, 'deptScope'],
    ];

    /** 「本部门 / 及以下」：把 create_by 落到「可见部门下的用户」 */
    public static function deptScope($query, array $deptIds): void
    {
        $userIds = $deptIds === []
            ? []
            : Db::name('user_dept')->whereIn('dept_id', $deptIds)->column('user_id');

        // fail-closed：没有可匹配的成员时不能放行任何数据
        $query->whereIn('create_by', $userIds ?: [0]);
    }
}
