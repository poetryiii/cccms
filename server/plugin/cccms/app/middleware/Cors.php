<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 跨域处理 + 链路 ID。
 *
 * 这里是中间件链的**第一环**，所以顺手在这里生成链路 ID（traceId）：
 *   1. 挂到 `$request->traceId`，后续中间件（OperationLog）与控制器都能读到；
 *   2. 回写响应头 `X-Trace-Id`，用户报障时可直接提供该值定位整条链路；
 *   3. 出错时 `Result::fail()` 也会把它放进响应体，页面报错弹窗里就能看到。
 *
 * 跨域下非简单响应头默认不可读，因此同时声明 `Access-Control-Expose-Headers`。
 */
class Cors implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $traceId = self::traceId();
        $request->traceId = $traceId;

        if ($request->method() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $handler($request);
        }

        return $response->withHeaders([
            'Access-Control-Allow-Origin'      => '*',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'false',
            'Access-Control-Max-Age'           => '86400',
            'Access-Control-Expose-Headers'    => 'X-Trace-Id',
            'X-Trace-Id'                       => $traceId,
        ]);
    }

    /** 形如 `260918143012-a1b2c3d4e5f6`：时间前缀便于排序，随机段避免碰撞 */
    private static function traceId(): string
    {
        return date('ymdHis') . '-' . bin2hex(random_bytes(6));
    }
}
