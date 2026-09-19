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
 *
 * 跨域来源取 `plugin.cccms.cors.origin` 白名单（`.env` 的 `CORS_ORIGIN`，逗号分隔）：
 * 命中才回显具体 Origin，未命中**一个 CORS 头都不下发**，由浏览器自行拦截。
 * 默认白名单为空 = 只允许同源（见 `config/cors.php` 的说明）。
 */
class Cors implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $traceId = self::traceId();
        $request->traceId = $traceId;

        $origin = self::allowedOrigin($request);

        if ($request->method() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $handler($request);
        }

        // 只有「带 Origin 且命中白名单」才下发 CORS 放行头：
        // 未命中时连 Allow-Methods / Allow-Headers 都不给，避免向未授权来源泄露接口形状；
        // 同源请求浏览器不做 CORS 校验，给了也没意义。
        $headers = ['X-Trace-Id' => $traceId];

        if ($origin !== '') {
            $headers += [
                'Access-Control-Allow-Origin'      => $origin,
                'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
                'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Requested-With',
                'Access-Control-Allow-Credentials' => 'false',
                'Access-Control-Max-Age'           => '86400',
                // 跨域下响应头默认不可读：滑动续期的 X-Refresh-Token 必须在此声明，
                // 否则分域部署时前端读不到续期令牌
                'Access-Control-Expose-Headers'    => 'X-Trace-Id, X-Refresh-Token',
            ];
        }

        // 带 Origin 的响应必须声明 Vary，否则中间缓存可能把「未放行来源」的响应
        // 复用给「已放行来源」。无 Origin（同源请求）时无需声明。
        if ((string)$request->header('Origin', '') !== '') {
            $headers['Vary'] = 'Origin';
        }

        return $response->withHeaders($headers);
    }

    /**
     * 命中白名单则返回原样回显的 Origin；未命中返回空串。
     *
     * 不返回 `*`：回显具体来源才能与 `Vary: Origin` 配合，也便于审计到底放行了谁。
     */
    private static function allowedOrigin(Request $request): string
    {
        $origin = (string)$request->header('Origin', '');
        if ($origin === '') {
            return '';
        }

        $allow = (string)config('plugin.cccms.cors.origin', '');
        if ($allow === '') {
            return '';
        }

        foreach (explode(',', $allow) as $item) {
            $item = trim($item);
            // Origin 按 RFC 6454 大小写不敏感，用 strcasecmp 比较
            if ($item !== '' && strcasecmp($item, $origin) === 0) {
                return $origin;
            }
        }

        return '';
    }

    /** 形如 `260918143012-a1b2c3d4e5f6`：时间前缀便于排序，随机段避免碰撞 */
    private static function traceId(): string
    {
        return date('ymdHis') . '-' . bin2hex(random_bytes(6));
    }
}
