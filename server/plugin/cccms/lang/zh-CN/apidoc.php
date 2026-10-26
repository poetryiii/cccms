<?php

declare(strict_types=1);

/** 接口文档（F1）文案。 */
return [
    'not_found' => '接口不存在或未生成文档（id 形如 GET /user）',

    // 左侧三层树的第一层：插件。每个插件都可以在自己的 `plugin/{插件}/lang/{locale}/apidoc.php`
    // 里声明 `plugin_{插件}`（语言包按命名空间合并）；没声明才退回 config/app.php 的 title
    // 或 db/menu.php 的首个菜单标题（见 OpenApi::pluginTitle）
    'plugin_cccms' => '内置模块',
    'plugin_index' => '前台站点',

    // 纯公开接口控制器的分组标题：这些控制器没有权限节点，挂不到菜单语言包上
    'group_auth' => '认证',
    'group_home' => '前台',
    'group_observability' => '可观测性',
    // 导出任务与个人中心没有独立菜单（入口在日志页 / 右上角），但接口文档要独立分组
    'group_export' => '导出任务',
    'group_profile' => '个人中心',
];
