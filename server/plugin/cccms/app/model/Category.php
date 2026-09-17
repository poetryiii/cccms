<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Category extends BaseModel
{
    protected $name = 'category';

    /** 公共基础数据（字典 / 附件共用的分类），不参与数据权限 */
    protected $dataScope = false;
}
