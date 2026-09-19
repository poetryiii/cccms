<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Role extends BaseModel
{
    protected $name = 'role';

    /** 参与多租户隔离：角色（含其档位与节点授权）按租户各自维护 */
    protected $tenantScope = true;

    /** 组织架构数据：不登记受控表，也不走预设基线 */
    protected $dataScope = false;
}
