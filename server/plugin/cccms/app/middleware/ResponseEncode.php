<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\PermissionMeta;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 请求形状约束：方法白名单（405）→ 编码白名单（406）。 */
class ResponseEncode implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $controller = $request->controller;
        if (!is_string($controller) || $controller === '') {
            return $handler($request);
        }

        $meta = PermissionMeta::of($controller, (string)$request->action);

        // 方法白名单
        if ($meta['methods'] !== [] && !in_array($request->method(), $meta['methods'], true)) {
            throw new ApiException("请求方法不允许：{$request->method()}", 405);
        }

        // 编码白名单
        $allow = $meta['encode'] ?? (array)config('plugin.cccms.response.default', ['json']);
        $want  = (string)$request->input('format', $allow[0] ?? 'json');
        if (!in_array($want, $allow, true)) {
            throw new ApiException("响应编码不允许：{$want}", 406);
        }
        $request->encode = $want;

        return $handler($request);
    }
}
