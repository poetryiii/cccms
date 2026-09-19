<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class DictType extends BaseModel
{
    protected $name = 'dict_type';

    /** 参与多租户隔离：字典在各租户内独立维护（平台租户的字典不自动下发给租户） */
    protected $tenantScope = true;

    /** 公共基础数据：租户内共享，不参与数据权限 */
    protected $dataScope = false;
}
