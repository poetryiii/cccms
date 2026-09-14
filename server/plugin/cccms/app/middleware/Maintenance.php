<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SysConfig;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 维护模式（system.maintenance）。
 *
 * 开启后仅超管可继续调用接口，其余已登录用户收到 503 + 维护公告。
 * 未登录请求放行（否则登录接口本身也会被拦，用户看不到任何提示）；
 * 非超管登录会被 AuthLogic 明确拒绝。
 *
 * 必须排在 CheckLogin 之后 —— 它依赖 $request->user。
 */
class Maintenance implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!SysConfig::getBool('system.maintenance', false)) {
            return $handler($request);
        }

        $user = $request->user;
        if ($user === null || $user->isSuperAdmin()) {
            return $handler($request);
        }

        throw new ApiException(
            SysConfig::getString('system.maintenance_notice', '系统维护中，请稍后访问') ?: '系统维护中，请稍后访问',
            503
        );
    }
}
