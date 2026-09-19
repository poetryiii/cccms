<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class DictData extends BaseModel
{
    protected $name = 'dict_data';

    /** 参与多租户隔离：随字典类型一起按租户隔离 */
    protected $tenantScope = true;

    /** 公共基础数据：租户内共享，不参与数据权限 */
    protected $dataScope = false;
}
