<?php

declare(strict_types=1);

use plugin\cccms\support\PermScanner;

return static function (): void {
    suite('权限节点 slug 规范');

    $matches = static fn (string $slug): bool => preg_match(PermScanner::SLUG_PATTERN, $slug) === 1;

    test('合法 slug（至少三段、全小写）', function () use ($matches): void {
        foreach ([
            'cccms:user:index',
            'cccms:user:reset_password',
            'cccms:data_rule:table_index',
            'shop:goods:save',
            'a:b:c',
            'x1:y2:z3',
            'cccms:user:index:extra',   // 多于三段也合法（用最长前缀挂菜单）
        ] as $slug) {
            ok($matches($slug), "应判为合法：{$slug}");
        }
    });

    test('非法 slug', function () use ($matches): void {
        foreach ([
            'cccms:user',              // 只有两段
            'cccms:user:',             // 尾段为空
            ':user:index',             // 首段为空
            'cccms::index',            // 中间段为空
            'Cccms:user:index',        // 首段含大写
            'cccms:User:index',        // 模块段含大写
            'cccms:user:Index',        // 动作段含大写
            '1cccms:user:index',       // 首字符不是字母
            'cccms-user-index',        // 分隔符错误
            'cccms:user:index-list',   // 动作段含连字符
            '',                        // 空
        ] as $slug) {
            ok(!$matches($slug), "应判为非法：{$slug}");
        }
    });
};
