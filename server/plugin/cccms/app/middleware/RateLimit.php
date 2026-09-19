<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\I18n;
use plugin\cccms\support\RateLimiter;
use plugin\cccms\support\Result;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 接口限流：按「用户 + 路由」计数，超限返回 429 + `Retry-After`。
 *
 * 位置在 `CheckLogin` **之后**：计数键需要用户身份（未登录的 `/auth/login`、`/auth/captcha`
 * 天然拿不到，且登录已有独立的失败锁定，见 `LoginThrottle`）。
 *
 * 超限时**直接返回响应**而不抛异常：`ApiException` 走 `ExceptionHandler` 渲染，
 * 拿不到地方挂 `Retry-After` 响应头；这里复用 `Result::fail()` 保证响应体结构与
 * 其它失败完全一致（Cors 在外层，`X-Trace-Id` 等响应头仍会补上）。
 */
class RateLimit implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $user = $request->user;

        // 未登录（含 #[NoLogin] 路由）不限流：此时没有稳定的身份维度，
        // 退回按 IP 会误伤同出口 IP 的同事
        if ($user === null) {
            return $handler($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        $verdict = RateLimiter::hit($user->id . ':' . $path, RateLimiter::isHeavy($path));

        if ($verdict['allowed']) {
            return $handler($request);
        }

        return Result::fail(
            I18n::t('common.too_many_requests', ['seconds' => $verdict['retry_after']]),
            429
        )->withHeaders([
            'Retry-After'           => (string)$verdict['retry_after'],
            'X-RateLimit-Limit'     => (string)$verdict['limit'],
            'X-RateLimit-Remaining' => '0',
        ]);
    }
}
