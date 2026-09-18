<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

/** 通知公告已读记录（关联表，无时间戳与软删除）。 */
class NoticeRead extends BaseModel
{
    protected $name = 'notice_read';

    protected $autoWriteTimestamp = false;

    protected $deleteTime = false;

    protected $dataScope = false;
}
