<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Role extends BaseModel
{
    protected $name = 'role';

    /** 组织架构数据：不登记受控表，也不走预设基线 */
    protected $dataScope = false;
}
