<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

/** 数据权限受控表（哪些表可以配数据权限）。 */
class DataScopeTable extends BaseModel
{
    protected $name = 'data_scope_table';

    /** 数据权限自身的元数据，不参与数据权限 */
    protected $dataScope = false;

    /** 没有 delete_time 列 */
    protected $deleteTime = false;
}
