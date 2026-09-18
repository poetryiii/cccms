<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\Upgrader;
use Throwable;

/**
 * 自动升级：把上游（Gitee 国内镜像 / GitHub 国外）的框架更新同步到本地。
 *
 * 与命令行 `php webman cccms:update` 共用同一套 `support\Upgrader` 实现，
 * 所以「页面点一下」和「手动跑命令」的结果完全一致：
 *
 *   - 只覆盖「本地没改过」的文件；本地改过的只报告，需显式勾选强制覆盖；
 *   - 任何覆盖 / 删除都会先备份到 `runtime/cccms-upgrade/backups/<时间戳>/`；
 *   - 本地独有的文件（业务插件）永远不会被碰。
 *
 * 基线记录的是**内容哈希**，与源无关，因此切换镜像不需要重建基线。
 */
final class UpgradeLogic
{
    /**
     * 页面初始化数据：环境、当前版本、可用源。
     *
     * @return array<string,mixed>
     */
    public static function overview(): array
    {
        $settings = Upgrader::settings();

        return [
            'enabled'        => (bool)$settings['enable'],
            'git_available'  => Upgrader::gitAvailable(),
            'initialized'    => Upgrader::initialized(),
            'current'        => Upgrader::current(),
            'sources'        => Upgrader::sources(),
            'default_source' => Upgrader::defaultSource(),
            'track'          => (string)$settings['track'],
            'default_base'   => (string)$settings['base'],
            'backup_dir'     => (string)$settings['backup_dir'],
        ];
    }

    /**
     * 检查更新（只读，不写任何文件）。
     *
     * @return array<string,mixed>
     */
    public static function check(string $source, ?string $ref): array
    {
        return self::present(Upgrader::plan($ref, true, $source));
    }

    /**
     * 远端可用版本（tag）。
     *
     * @return array<int,string>
     */
    public static function tags(string $source): array
    {
        return Upgrader::remoteTags($source);
    }

    /**
     * 建立基线：记录「当前代码基于的上游版本」。
     *
     * @return array<string,mixed>
     */
    public static function init(string $source, ?string $ref): array
    {
        $result = Upgrader::init($ref, true, $source);

        return [
            'source'     => $result['source'],
            'ref'        => $result['ref'],
            'commit'     => $result['commit'],
            'fallback'   => $result['fallback'],
            'total'      => $result['total'],
            'modified'   => count($result['modified']),
            'missing'    => count($result['missing']),
            'local_only' => count($result['localOnly']),
        ];
    }

    /**
     * 执行升级：写入 / 覆盖 / 删除，然后同步菜单、权限节点与缓存。
     *
     * 后置动作复用 `MaintenanceLogic::refresh('all')`，与顶栏「系统同步」按钮同一实现，
     * 免得升级完还得让人再手动点一次。
     *
     * @return array<string,mixed>
     */
    public static function run(string $source, ?string $ref, bool $force, bool $prune): array
    {
        $plan   = Upgrader::plan($ref, true, $source);
        $result = Upgrader::apply($plan, $force, $prune);

        $maintenance      = null;
        $maintenanceError = '';
        try {
            $maintenance = MaintenanceLogic::refresh('all');
        } catch (Throwable $e) {
            // 升级本身已成功，后置同步失败不该让整次操作显示为失败
            $maintenanceError = $e->getMessage();
        }

        $changed = $result['written'] > 0 || $result['removed'] > 0;

        return [
            'ref'               => (string)$plan['ref'],
            'commit'            => (string)$plan['commit'],
            'written'           => $result['written'],
            'removed'           => $result['removed'],
            'backed'            => $result['backed'],
            'skipped'           => $result['skipped'],
            'backup_dir'        => $result['backupDir'],
            'report'            => $result['report'],
            'maintenance'       => $maintenance,
            'maintenance_error' => $maintenanceError,
            'need_reload'       => $changed,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * 精简 plan：前端不需要仓库物理路径，也避免把服务器目录结构透出去。
     *
     * @param  array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function present(array $plan): array
    {
        $summary = (array)$plan['summary'];

        return [
            'source'      => $plan['source'],
            'ref'         => $plan['ref'],
            'commit'      => $plan['commit'],
            'base'        => $plan['base'],
            'base_commit' => $plan['base_commit'],
            'summary'     => $summary,
            'files'       => $plan['files'],
            'commits'     => $plan['commits'],
            'lines'       => $plan['lines'],
            'local_only'  => count((array)$plan['localOnly']),
            'upgradable'  => (int)$summary[Upgrader::SAFE] + (int)$summary[Upgrader::NEW],
            'pending'     => (int)$summary[Upgrader::CONFLICT],
            'has_update'  => (int)$summary[Upgrader::SAFE] + (int)$summary[Upgrader::NEW]
                + (int)$summary[Upgrader::CONFLICT] + (int)$summary[Upgrader::REMOVED] > 0,
        ];
    }
}
