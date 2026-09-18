<?php

declare(strict_types=1);

namespace plugin\cccms\command\task;

use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\Upgrader;

/**
 * 上游更新巡检（框架内置定时任务）。
 *
 * 只**比对不写文件**：把「上游有没有新改动、有几个文件需要人工合并」写进定时任务执行日志。
 * 真正落盤永远由人执行 `php webman cccms:update`（或显式 `--force`）完成，
 * 避免无人值守场景下覆盖掉业务定制。
 *
 * 建议在后台「定时任务」里配成每天执行一次，例如 Cron：`0 0 9 * * *`（每天 09:00）。
 *
 * 参数可覆盖目标版本（便于盯某个分支 / 预发布 tag）：
 *   {"ref": "v0.0.2"}
 */
class UpgradeCheckTask implements CrontabTask
{
    public function run(array $params = []): string
    {
        if (!(bool)Upgrader::settings()['enable']) {
            return '已跳过：上游同步已关闭（plugin.cccms.upgrade.enable = false）';
        }

        if (!Upgrader::initialized()) {
            return '尚未建立基线：请先执行 php webman cccms:update --init';
        }

        if (!Upgrader::gitAvailable()) {
            return '检查未完成：未检测到 git 可执行文件（可在 plugin/cccms/config/upgrade.php 配置绝对路径）';
        }

        try {
            $ref  = isset($params['ref']) ? (string)$params['ref'] : null;
            $plan = Upgrader::plan($ref);

            $text = '上游巡检完成（目标 ' . $plan['ref'] . '）：' . Upgrader::summarizeText($plan);

            $conflicts = array_keys(array_filter(
                (array)$plan['items'],
                static fn (string $kind): bool => $kind === Upgrader::CONFLICT
            ));

            if ($conflicts !== []) {
                $text .= '。需人工合并 ' . count($conflicts) . ' 个文件，执行 php webman cccms:update 查看清单';
            }

            return $text;
        } catch (\Throwable $e) {
            return '上游巡检失败：' . $e->getMessage();
        }
    }
}
