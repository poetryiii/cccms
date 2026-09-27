<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\EndpointAccess;
use plugin\cccms\support\HealthProbe;
use plugin\cccms\support\Metrics;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 可观测性端点：`GET /healthz`（依赖探针）与 `GET /metrics`（Prometheus 指标）。
 *
 * ## 为什么两者都要 IP 白名单
 *
 * 这两个端点的响应里包含「依赖是否可用」「磁盘与内存余量」「定时任务最近成功与否」
 * 这类**内部拓扑信息**，公开出去等于给攻击者一份「哪个环节最脆弱」的清单。
 * 因此统一只对白名单开放（`security.metrics_allow_ips`，默认 `127.0.0.1,::1`）：
 *   - 容器内的 `HEALTHCHECK` / `docker compose` 探活来自回环地址，开箱可用；
 *   - Prometheus 抓取端若在别的机器 / 容器网段，把它的地址或网段填进白名单即可。
 *
 * 非白名单来源**返回 404 而不是 403**：403 等于承认「这个路径存在且只是没权限」，
 * 对探测者来说是有效信息；404 与「路由不存在」不可区分。
 *
 * ## 与 `GET /ping` 的分工
 *
 * `/ping` 仍保留为**存活探针**（不碰任何外部依赖、永远 200），
 * `/healthz` 是**就绪探针**（依赖故障返回 503）。两者不互相替代：
 * 前者回答「进程活着吗」，后者回答「现在能对外服务吗」。
 */
class ObservabilityController extends BaseController
{
    /**
     * 依赖探针：MySQL / Redis / 磁盘 / 内存。
     *
     * 全部正常 → 200 + 明细；任一失败 → 503 + 明细（**指明失败项**，而不是只说「不健康」）。
     */
    #[NoLogin]
    public function healthz(Request $request): Response
    {
        if (!self::allowed($request)) {
            return self::notFound();
        }

        $report = HealthProbe::run();

        return self::json($report, $report['ok'] ? 200 : 503);
    }

    /** Prometheus 抓取端点（文本格式，非 JSON 信封） */
    #[NoLogin]
    public function metrics(Request $request): Response
    {
        if (!self::allowed($request)) {
            return self::notFound();
        }

        return new Response(
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
            Metrics::render(Metrics::collect())
        );
    }

    private static function allowed(Request $request): bool
    {
        return EndpointAccess::allowed($request);
    }

    /**
     * 统一的 404。
     *
     * 刻意不返回 JSON 信封、也不带任何提示语：与框架的「路由不存在」响应保持同一形状，
     * 避免通过响应体差异推断端点是否存在。
     */
    private static function notFound(): Response
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found');
    }

    /** @param array<string,mixed> $data */
    private static function json(array $data, int $status): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
