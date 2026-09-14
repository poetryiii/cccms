<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 定时任务契约。
 *
 * 安全设计：调度进程只执行**实现本接口的类**，因此「哪些代码可被定时执行」
 * 完全由代码决定，数据库中存的目标类名无法指向任意方法（杜绝 RCE）。
 */
interface CrontabTask
{
    /**
     * 执行任务。
     *
     * @param array<string,mixed> $params 来自 sys_crontab.params
     * @return string 输出摘要（写入 sys_crontab_log.output）
     */
    public function run(array $params = []): string;
}
