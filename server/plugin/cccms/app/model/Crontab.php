<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class Crontab extends BaseModel
{
    protected $name = 'crontab';

    /**
     * 参与多租户隔离：任务定义按租户隔离。
     *
     * 注意执行侧：调度进程 / CLI 没有用户上下文，租户作用域自动跳过，
     * 因此所有租户的任务都会被正常调度 —— 隔离的是「谁能看到 / 改这条任务」，
     * 不是「任务会不会跑」。
     */
    protected $tenantScope = true;

    protected $json = ['params'];

    /**
     * 数据权限：sys_crontab 既无 owner 也无部门列，
     * 跳过预设基线（否则「仅本人 / 本部门」会拼出不存在的列），
     * 隔离完全由自定义行级规则表达。
     */
    protected $dataScope = [
        'no_baseline' => true,
    ];
}
