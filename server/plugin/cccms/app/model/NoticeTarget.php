<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

/**
 * 通知公告定向投放目标（关联表，无时间戳与软删除）。
 *
 * `target_type` 区分三种目标：dept（部门，含下级）/ role（角色，含后代）/ user（指定用户），
 * 与 `sys_notice.scope` 一一对应。这里单独存 type 是为了查询时能精确按类型匹配，
 * 避免「部门 id=5 与 用户 id=5」在同一个 id 空间里串味。
 */
class NoticeTarget extends BaseModel
{
    protected $name = 'notice_target';

    protected $autoWriteTimestamp = false;

    protected $deleteTime = false;

    protected $dataScope = false;
}
