<?php

declare(strict_types=1);

/**
 * 通用文案（后端）。
 *
 * key 约定：`{命名空间}.{键名}`，命名空间对应本目录下的文件名。
 * 缺 key 时 I18n::t() 会回落到 zh-CN；两边都缺才返回 key 原文并记 warning。
 */
return [
    'not_logged_in'          => '未登录',
    'session_expired'        => '登录状态已失效，请重新登录',
    'invalid_credentials'    => '登录凭证无效或用户已失效',
    'unauthorized'           => '未登录或登录已失效',
    'no_permission'          => '权限不足：{slug}',
    'permission_not_declared' => '接口未声明权限：{target}',
];
