<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class DataRule extends BaseModel
{
    protected $name = 'data_rule';

    /** 数据权限规则自身：被过滤会出现「管理员看不到自己配的规则」，不参与 */
    protected $dataScope = false;
    protected $json = ['dept_ids'];
}
