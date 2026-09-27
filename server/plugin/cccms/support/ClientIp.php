<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use Webman\Http\Request;

/**
 * 客户端真实 IP 溯源（反代场景）。
 *
 * ## 为什么不能直接用 `$request->getRealIp()`
 *
 * Webman 内置实现是 `safeMode` 语义：**直连 IP 不在内网段**时返回直连 IP（可信），
 * 否则取 `X-Forwarded-For` 的**第一个**值。
 *
 * 本项目按 Docker / Nginx 反代部署，直连 IP 恰好是内网（网关）地址，于是必然走到
 * 「取 XFF 第一个值」这条分支 —— 而 XFF 是**客户端可以自己带的头**。攻击者只要每次
 * 换一个 `X-Forwarded-For`，就能让「IP 维度」的登录失败限流（`security.login_fail_ip_limit`）
 * 形同虚设，也能把伪造 IP 写进登录日志与操作日志。
 *
 * ## 本类的算法
 *
 * 1. **只有直连来源命中 `security.trusted_proxies`（CIDR 白名单）时才解析转发头**；
 *    未配置或直连来源不可信（客户端绕过网关直连应用端口）一律返回直连 IP —— 转发头
 *    可任意伪造，只有可信代理写入的部分才作数。
 * 2. 命中后采用**取最右非可信 IP** 的标准算法：从 XFF 最右端（离服务端最近）向左走，
 *    跳过全部可信代理，遇到的第一个非可信地址就是真实客户端。
 *    这样即使攻击者在左侧拼接任意伪造条目，也会在到达它们之前先命中自己的真实出口 IP。
 * 3. 整条链都是可信代理（例如代理未追加客户端地址）时，退回最左侧记录。
 *
 * ## 部署要求
 *
 * 反向代理**必须**覆写而不是透传 `X-Forwarded-For`（Nginx 用
 * `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`），
 * 并在后台「配置管理 → 安全 → 可信代理」把代理地址填进白名单，
 * 详见 [02-安装部署](docs/02-安装部署.md)。
 */
final class ClientIp
{
    /**
     * 解析本次请求的客户端 IP。
     *
     * 永远返回一个字符串：解析不出来时返回 `''`（调用方按 `?: '-'` 之类兜底展示），
     * 绝不抛错 —— IP 只是审计与限流的输入，不该让请求失败。
     */
    public static function resolve(Request $request): string
    {
        $direct = self::normalize((string)$request->getRemoteIp());
        if ($direct === '') {
            return '';
        }

        return self::pick($direct, self::forwardedChain($request), self::trustedProxies());
    }

    /**
     * 「取最右非可信 IP」核心算法（纯函数，便于单测）。
     *
     * @param string   $direct  直连来源 IP（TCP 对端）
     * @param string[] $chain   XFF 解析结果，按「客户端 → 服务端」顺序
     * @param string[] $trusted 可信代理 CIDR 列表
     */
    public static function pick(string $direct, array $chain, array $trusted): string
    {
        // 未配置可信代理，或直连来源本身不可信：转发头一律不采信
        if ($trusted === [] || !self::isTrusted($direct, $trusted)) {
            return $direct;
        }

        if ($chain === []) {
            return $direct;
        }

        // 从右往左找第一个非可信地址；XFF 最右端最接近服务端，也最难被伪造
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = trim((string)$chain[$i]);
            if ($ip !== '' && !self::isTrusted($ip, $trusted)) {
                return $ip;
            }
        }

        // 整条链都是可信代理：退回最早的记录（至少比直连代理 IP 更接近客户端）
        return trim((string)$chain[0]);
    }

    /**
     * 解析 XFF 头文本（纯函数）。
     *
     * 非法条目直接丢弃（不占位），使「攻击者在链中插垃圾串」无法顶掉有效条目。
     *
     * @return string[] 按「客户端 → 服务端」顺序
     */
    public static function parseChain(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $header) as $item) {
            $ip = self::normalize($item);
            if ($ip !== '') {
                $out[] = $ip;
            }
        }

        return $out;
    }

    /**
     * 可信代理 CIDR 列表（`security.trusted_proxies`）。
     *
     * 支持单 IP 与 `a.b.c.d/nn` / IPv6；允许用逗号或换行分隔。非法条目被静默忽略 ——
     * 配错一个字不该让整个后台的 IP 溯源停摆，`cccms:data-scope-check` 之外还有
     * 文档中的「怎么验证生效」步骤兜底。
     *
     * @return string[]
     */
    public static function trustedProxies(): array
    {
        return self::parseList(SysConfig::getString('security.trusted_proxies'));
    }

    /**
     * 解析「IP / CIDR 列表」配置文本（逗号或空白分隔，非法条目静默忽略）。
     *
     * 抽成公开方法是因为除可信代理之外还有别的白名单要用同一套语法与容错策略
     * （如 `/metrics`、`/healthz` 的 `security.metrics_allow_ips`）。
     *
     * @return string[]
     */
    public static function parseList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '' && self::parseCidr($item) !== null) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** IP 是否命中 CIDR 白名单中的任一条 */
    public static function isTrusted(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::match($ip, (string)$cidr)) {
                return true;
            }
        }

        return false;
    }

    /** 单个 IP 是否落在某条 CIDR 内（IPv4 / IPv6 均按位前缀比较） */
    public static function match(string $ip, string $cidr): bool
    {
        $parsed = self::parseCidr($cidr);
        $packed = @inet_pton(self::normalize($ip));
        if ($parsed === null || $packed === false) {
            return false;
        }

        [$network, $bits] = $parsed;
        if (strlen($packed) !== strlen($network)) {
            return false;
        }

        return self::maskPrefix($packed, $bits) === self::maskPrefix($network, $bits);
    }

    /**
     * 解析 CIDR 表达式。
     *
     * @return array{0:string,1:int}|null [网络地址(二进制), 前缀长度]；非法返回 null
     */
    private static function parseCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }

        $parts  = explode('/', $cidr, 2);
        $packed = @inet_pton(trim($parts[0]));
        if ($packed === false) {
            return null;
        }

        $maxBits = strlen($packed) * 8;
        $bits    = $maxBits;
        if (isset($parts[1])) {
            $part = trim($parts[1]);
            if ($part === '' || !ctype_digit($part)) {
                return null;
            }
            $bits = (int)$part;
            if ($bits > $maxBits) {
                return null;
            }
        }

        return [$packed, $bits];
    }

    /** 保留前 `$bits` 位、其余置 0（逐字节掩码，避免大整数运算的可移植性问题） */
    private static function maskPrefix(string $packed, int $bits): string
    {
        $out = '';
        for ($i = 0, $len = strlen($packed); $i < $len; $i++) {
            $keep = $bits - $i * 8;
            if ($keep >= 8) {
                $out .= $packed[$i];
            } elseif ($keep <= 0) {
                $out .= "\x00";
            } else {
                $out .= chr(ord($packed[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
            }
        }

        return $out;
    }

    /**
     * XFF 头解析成**按「客户端 → 服务端」顺序**的合法 IP 列表。
     *
     * @return string[]
     */
    private static function forwardedChain(Request $request): array
    {
        return self::parseChain((string)$request->header('x-forwarded-for', ''));
    }

    /** 归一化：剥掉 `[::1]:port` / `1.2.3.4:port` 的端口与方括号，仅保留合法 IP */
    private static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // IPv6 带端口写作 `[2001:db8::1]:443`
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            $value = $end === false ? $value : substr($value, 1, $end - 1);
        } elseif (substr_count($value, ':') === 1) {
            // `1.2.3.4:443`；IPv6 不带方括号时冒号必然多于一个，不能按端口切
            $value = explode(':', $value, 2)[0];
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : '';
    }
}
