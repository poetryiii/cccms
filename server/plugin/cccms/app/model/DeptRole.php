<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class DeptRole extends BaseModel
{
    protected $name = 'dept_role';

    /** 关联表没有时间戳列 */
    protected $autoWriteTimestamp = false;

    /** 关联表不参与数据权限 */
    protected $dataScope = false;

    /** 没有 delete_time 列 */
    protected $deleteTime = false;
}
