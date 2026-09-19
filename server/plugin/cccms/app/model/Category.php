<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Category extends BaseModel
{
    protected $name = 'category';

    /** 参与多租户隔离：分类属于租户自己的字典 / 附件体系 */
    protected $tenantScope = true;

    /** 公共基础数据（字典 / 附件共用的分类），不参与数据权限 */
    protected $dataScope = false;
}
