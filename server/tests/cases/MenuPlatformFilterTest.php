<?php

declare(strict_types=1);

use plugin\cccms\app\logic\MenuLogic;
use plugin\cccms\support\UserContext;

/**
 * 平台级菜单在「切换到其它租户」后隐藏。
 *
 * 只覆盖 `MenuLogic::userTree()` 的租户过滤：超管在平台租户能看到「租户管理」，
 * 切到真实租户后「租户管理」菜单与其按钮一并消失（非平台级菜单不受影响）。
 * 需要真实 MySQL（`sys_menu` 已由 menu-sync 写入 cccms:tenant 节点）。
 */
return static function (): void {
    suite('平台级菜单的租户过滤');

    /** 递归收集菜单树里所有 node slug（含按钮） */
    $collectNodes = static function (array $nodes) use (&$collectNodes): array {
        $out = [];
        foreach ($nodes as $node) {
            $slug = (string)($node['node'] ?? '');
            if ($slug !== '') {
                $out[] = $slug;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $out = array_merge($out, $collectNodes($node['children']));
            }
        }

        return $out;
    };

    test('平台租户下的超管能看到「租户管理」', function () use ($collectNodes): void {
        $tree  = MenuLogic::userTree(new UserContext(1, 'admin', superAdmin: true, tenantId: 0));
        $nodes = $collectNodes($tree);

        ok(in_array('cccms:tenant', $nodes, true), '平台租户应含「租户管理」菜单');
        ok(in_array('cccms:tenant:index', $nodes, true), '平台租户应含「租户列表」按钮');
    }, true);

    test('切换到其它租户后，「租户管理」菜单与按钮一并隐藏', function () use ($collectNodes): void {
        $tree  = MenuLogic::userTree(new UserContext(1, 'admin', superAdmin: true, tenantId: 5));
        $nodes = $collectNodes($tree);

        ok(!in_array('cccms:tenant', $nodes, true), '切换租户后不应含「租户管理」菜单');
        ok(!in_array('cccms:tenant:index', $nodes, true), '切换租户后不应含「租户列表」按钮');
        ok(in_array('cccms:user', $nodes, true), '非平台级菜单（用户管理）应保留');
    }, true);
};
