<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\PermissionMeta;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 授权：fail-closed 校验 slug。 */
class CheckAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $controller = $request->controller;
        if (!is_string($controller) || $controller === '') {
            return $handler($request);
        }

        $meta = PermissionMeta::of($controller, (string)$request->action);

        if ($meta['noLogin']) {
            return $handler($request);
        }

        $user = $request->user;
        if ($user === null) {
            throw new ApiException('未登录或登录已失效', 401);
        }

        if ($meta['noAuth'] || $user->isSuperAdmin()) {
            return $handler($request);
        }

        if ($meta['slug'] === null) {
            // fail-closed：接口未声明权限，直接拒绝
            throw new ApiException("接口未声明权限：{$controller}::{$request->action}", 500);
        }

        if (!$user->hasPermission($meta['slug'])) {
            throw new ApiException("权限不足：{$meta['slug']}", 403);
        }

        return $handler($request);
    }
}
