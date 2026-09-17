<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use think\facade\Db;

class OperationLog extends BaseModel
{
    protected $name = 'log';

    /** sys_log 只有 create_time，没有 update_time（否则模型写入会拼出不存在的列） */
    protected $updateTime = false;

    /** 没有 delete_time 列：日志删除即物理删除 */
    protected $deleteTime = false;

    /**
     * 数据权限（只读场景）：
     *   - 「仅本人」= 只看自己的操作日志（user_id）；
     *   - 「本部门 / 及以下」= 可见部门成员的操作日志。
     */
    protected $dataScope = [
        'owner' => 'user_id',
        'dept'  => [self::class, 'deptScope'],
    ];

    /** 「本部门 / 及以下」：把 user_id 落到「可见部门下的用户」 */
    public static function deptScope($query, array $deptIds): void
    {
        $userIds = $deptIds === []
            ? []
            : Db::name('user_dept')->whereIn('dept_id', $deptIds)->column('user_id');

        // fail-closed：没有可匹配的成员时不能放行任何数据
        $query->whereIn('user_id', $userIds ?: [0]);
    }
}
