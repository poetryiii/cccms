<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Crontab extends BaseModel
{
    protected $name = 'crontab';

    protected $json = ['params'];

    /**
     * 数据权限：sys_crontab 既无 owner 也无部门列，
     * 跳过预设基线（否则「仅本人 / 本部门」会拼出不存在的列），
     * 隔离完全由自定义行级规则表达。
     */
    protected $dataScope = [
        'no_baseline' => true,
    ];
}
