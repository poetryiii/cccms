<?php

/**
 * 上游代码同步配置（`php webman cccms:update` 与后台「自动升级」页面共用）。
 *
 * CCCMS 是「框架 + 插件」结构：业务在独立插件里演进，框架本身也会持续更新。
 * 本配置决定从哪些仓库同步、同步哪些范围，以及本地状态与备份放在哪里。
 *
 * 核心思路：不覆盖「本地改过」的文件。判定依据是三份哈希比对
 * （基线 / 本地 / 上游），详见 plugin/cccms/support/Upgrader.php 类注释。
 */
return [
    // 总开关。false 时命令、页面与监控定时任务都会直接跳过
    'enable' => filter_var(getenv('CCCMS_UPGRADE_ENABLE') ?: 'true', FILTER_VALIDATE_BOOL),

    // 同步源：可配多个镜像，命令用 --source、页面用下拉切换。
    // 基线记录的是「内容哈希」，与源无关，因此换源不需要重建基线。
    'remotes' => [
        'gitee'  => [
            'label' => 'Gitee（国内镜像）',
            'url'   => getenv('CCCMS_UPGRADE_URL_GITEE') ?: 'https://gitee.com/poetryiii/cccms.git',
        ],
        'github' => [
            'label' => 'GitHub（国外）',
            'url'   => getenv('CCCMS_UPGRADE_URL_GITHUB') ?: 'https://github.com/poetryiii/cccms.git',
        ],
    ],

    // 默认同步源（上面 remotes 的 key）
    'default_source' => getenv('CCCMS_UPGRADE_SOURCE') ?: 'gitee',

    // 跟踪目标：分支名 / tag / commit。页面与命令都可以临时指定其它版本
    'track' => getenv('CCCMS_UPGRADE_TRACK') ?: 'main',

    // 基线版本：首次初始化时，记录「当前代码基于的上游版本」
    'base' => getenv('CCCMS_UPGRADE_BASE') ?: 'v0.0.1',

    // 同步范围：留空 = 同步全仓库（推荐）；填写后只同步这些路径前缀
    'include' => [],

    // 永不覆盖 / 删除的路径（相对项目根）。上游清单里命中这些前缀的文件一律忽略
    'exclude' => [
        '.git',
        '.github',
        'server/.env',
        'server/vendor',
        'server/runtime',
        'server/tests/tmp',
        'frontend/.env',
        'frontend/.env.local',
        'frontend/.env.production',
        'frontend/node_modules',
        'frontend/dist',
    ],

    // 运行时目录（相对 server 根）：上游仓库缓存（按源分目录）/ 基线状态 / 覆盖前备份
    'cache_dir'  => 'runtime/cccms-upgrade/repo',
    'state_file' => 'runtime/cccms-upgrade/state.json',
    'backup_dir' => 'runtime/cccms-upgrade/backups',

    // git 可执行文件（不在 PATH 里时可写绝对路径）
    'git' => getenv('CCCMS_UPGRADE_GIT') ?: 'git',
];
