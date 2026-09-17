<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Config extends BaseModel
{
    protected $name = 'config';

    /** 全局配置：无归属维度，不参与数据权限 */
    protected $dataScope = false;

    /** 没有 delete_time 列 */
    protected $deleteTime = false;
    protected $json = ['options'];
}
