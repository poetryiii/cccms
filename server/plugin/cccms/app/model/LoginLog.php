<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

/**
 * 登录日志。
 *
 * 数据权限声明为**不参与**：登录日志是全局审计数据，失败登录的 user_id 为 0，
 * 若按「仅本人」收窄，审计者将看不到失败尝试 —— 那正是最需要看到的部分。
 * 访问由权限节点 `cccms:login_log:index` 控制。
 */
class LoginLog extends BaseModel
{
    protected $name = 'login_log';

    /** 只有 create_time */
    protected $updateTime = false;

    /** 没有 delete_time：删除即物理删除 */
    protected $deleteTime = false;

    protected $dataScope = false;
}
