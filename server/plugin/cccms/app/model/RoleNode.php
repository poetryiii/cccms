<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class RoleNode extends BaseModel
{
    protected $name = 'role_node';

    /** 关联表没有时间戳列 */
    protected $autoWriteTimestamp = false;

    /** 角色↔节点关联：不参与数据权限 */
    protected $dataScope = false;

    /** 没有 delete_time 列 */
    protected $deleteTime = false;
}
