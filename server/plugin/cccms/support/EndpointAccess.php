<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use Webman\Http\Request;

/**
 * 可观测端点（`/healthz`、`/metrics`）的访问白名单。
 *
 * 为什么单独成类而不是放在控制器里：`PermScanner` 要求**控制器的每个公开方法**
 * 都声明权限注解（fail-closed），而这个判定是纯策略、不该出现在控制器的方法表里；
 * 放到 support 后既能被单测直接调用，也不会被误当成一个接口动作。
 *
 * 判定用的是**真实客户端 IP**（`ClientIp::resolve()`，含可信代理溯源）而不是 TCP 对端：
 * 反代部署下应用看到的对端永远是网关，拿对端判断会让白名单形同虚设。
 */
final class EndpointAccess
{
    /** 白名单配置项名 */
    private const CONFIG = 'security.metrics_allow_ips';

    /**
     * 配置留空时的兜底：**仅本机**。
     *
     * 容器内的 `HEALTHCHECK` / `docker compose` 探活来自回环地址，因此新装环境开箱可用；
     * 同时不会把「依赖是否可用、磁盘还剩多少」这类内部拓扑信息暴露到公网。
     */
    private const DEFAULT_ALLOW = ['127.0.0.1', '::1'];

    /** 请求来源是否在白名单内 */
    public static function allowed(Request $request): bool
    {
        return self::allowedIp(ClientIp::resolve($request));
    }

    /**
     * IP 判定（纯函数，便于单测）。
     *
     * 取不到 IP（`''`）或 IP 非法时**拒绝**（fail-closed）：这两个端点的信息量与
     * 可用性都足够敏感，宁可让配错的人打不开，也不要默认放行。
     */
    public static function allowedIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $allow = ClientIp::parseList(SysConfig::getString(self::CONFIG));
        if ($allow === []) {
            $allow = self::DEFAULT_ALLOW;
        }

        return ClientIp::isTrusted($ip, $allow);
    }
}
