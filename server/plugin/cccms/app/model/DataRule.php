<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class DataRule extends BaseModel
{
    protected $name = 'data_rule';

    /**
     * 参与多租户隔离：规则只在本租户内生效。
     *
     * 这一条是**安全关键**：规则里存在「四个绑定全空 = 全局规则」的形态，
     * 若不按租户隔离，A 租户配的全局规则会连 B 租户的用户一起命中。
     */
    protected $tenantScope = true;

    /** 数据权限规则自身：被过滤会出现「管理员看不到自己配的规则」，不参与 */
    protected $dataScope = false;
    protected $json = ['dept_ids'];
}
