<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Post extends BaseModel
{
    protected $name = 'post';

    /** 参与多租户隔离：岗位按租户各自维护 */
    protected $tenantScope = true;

    /** 组织架构数据：不登记受控表，也不走预设基线 */
    protected $dataScope = false;
}
