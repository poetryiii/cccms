<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class CrontabLog extends BaseModel
{
    protected $name = 'crontab_log';

    /** 日志明细：随 sys_crontab 归属，不参与数据权限 */
    protected $dataScope = false;

    /** 没有 delete_time 列 */
    protected $deleteTime = false;
    protected $autoWriteTimestamp = false;
}
