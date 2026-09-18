<?php

declare(strict_types=1);

use plugin\cccms\app\controller\AuthController;
use plugin\cccms\app\controller\NoticeController;
use plugin\cccms\support\PermissionMeta;
use plugin\cccms\support\UserContext;

/**
 * 鉴权矩阵（集成）：直接读真实控制器上的注解，验证「唯一声明机制」的解析结果
 * 与 fail-closed 判定依据。CheckAuth 中间件正是依据这些解析结果决定 401 / 403 / 500。
 *
 * 矩阵回顾（见 docs/05）：
 *   - #[NoLogin]  → 未登录放行；#[NoAuth] → 登录即可；#[Permission] → 校验 slug；
 *   - 未声明注解 → slug=null → CheckAuth 拒绝（500，fail-closed）；
 *   - 超管 → 跳过 slug 校验。
 */
return static function (): void {
    suite('鉴权矩阵（注解解析 + 判定）');

    test('Permission 注解解析出 slug 与 title', function (): void {
        $meta = PermissionMeta::of(NoticeController::class, 'index');
        same('cccms:notice:index', $meta['slug']);
        same('通知公告列表', $meta['title']);
        ok($meta['noAuth'] === false && $meta['noLogin'] === false, 'Permission 不该同时是 NoAuth/NoLogin');
    });

    test('NoAuth 注解：登录即可，不产生权限点', function (): void {
        $meta = PermissionMeta::of(AuthController::class, 'me');
        ok($meta['noAuth'] === true, 'noAuth 应为 true');
        same(null, $meta['slug'], 'NoAuth 不应产生 slug');
        ok($meta['noLogin'] === false, 'noLogin 应为 false');
    });

    test('NoAuth 带 title 时作为操作名（注销）', function (): void {
        $meta = PermissionMeta::of(AuthController::class, 'logout');
        ok($meta['noAuth'] === true, 'noAuth 应为 true');
        same('注销', $meta['title']);
    });

    test('NoLogin 注解：未登录即可访问（登录前接口）', function (): void {
        $meta = PermissionMeta::of(AuthController::class, 'ping');
        ok($meta['noLogin'] === true, 'noLogin 应为 true');
        same(null, $meta['slug']);
    });

    test('NoLogin 与 Restrict 共存（登录接口只允许 POST）', function (): void {
        $meta = PermissionMeta::of(AuthController::class, 'login');
        ok($meta['noLogin'] === true, 'noLogin 应为 true');
        same(['POST'], $meta['methods'], 'Restrict 应约束为 POST');
    });

    test('未声明注解的方法/控制器回退默认（fail-closed 信号）', function (): void {
        // 不存在的控制器/方法 → 默认 meta（slug=null 且非 NoAuth/NoLogin），CheckAuth 据此 500
        $meta = PermissionMeta::of('plugin\\cccms\\app\\controller\\NotExistsController', 'someAction');
        same(null, $meta['slug']);
        ok($meta['noAuth'] === false && $meta['noLogin'] === false, '默认 meta 应是 fail-closed 状态');
    });

    test('超管绕过权限判定；普通用户按 slug 判定', function (): void {
        $super = new UserContext(1, 'admin', superAdmin: true);
        ok($super->hasPermission('cccms:anything'), '超管应有任意权限');

        $user = new UserContext(2, 'bob', permissions: ['cccms:user:index']);
        ok($user->hasPermission('cccms:user:index'), '已授权节点应放行');
        ok(!$user->hasPermission('cccms:user:save'), '未授权节点应拒绝');
    });
};
