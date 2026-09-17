<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class DictData extends BaseModel
{
    protected $name = 'dict_data';

    /** 公共基础数据：全系统共享，不参与数据权限 */
    protected $dataScope = false;
}
