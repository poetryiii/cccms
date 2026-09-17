<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Menu extends BaseModel
{
    protected $name = 'menu';

    /** 权限节点树：按角色隔离，与数据权限正交（perm-scan / menu-sync 也会直接查它） */
    protected $dataScope = false;
}
