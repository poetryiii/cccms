<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use Throwable;

/**
 * 通用短信网关（HTTP / curl 驱动，零 composer 依赖）。
 *
 * 刻意**不绑定具体厂商**：不同短信服务的差异集中在 URL、请求方法、参数名与请求头上，
 * 把这些做成配置项（`sms.*`）后，接任意网关都只改配置、不改代码。
 *
 * 配置：
 *   sms.enabled     总开关
 *   sms.gateway_url 网关地址
 *   sms.method      GET / POST
 *   sms.params      参数模板（JSON），值里 `{mobile}` / `{code}` / `{sign}` 会被替换
 *   sms.headers     附加请求头（JSON）
 *   sms.sign_name   短信签名
 *
 * GET 时参数拼到 query；POST 时作为 JSON 请求体发送（未显式指定 Content-Type 时补 application/json）。
 * 失败返回 false 并记 warning，不抛异常（由调用方统一处理，避免泄露账号存在性）。
 */
final class SmsSender
{
    private const TIMEOUT = 10;

    public static function enabled(): bool
    {
        return SysConfig::getBool('sms.enabled', false) && SysConfig::getString('sms.gateway_url') !== '';
    }

    /** 发送验证码短信；成功（HTTP 2xx）true，否则 false */
    public static function send(string $mobile, string $code, string $signName = ''): bool
    {
        if (!self::enabled() || trim($mobile) === '') {
            return false;
        }
        if (!function_exists('curl_init')) {
            Log::warning('sms send failed: curl 扩展未安装');

            return false;
        }

        $method = strtoupper(SysConfig::getString('sms.method', 'POST')) === 'GET' ? 'GET' : 'POST';
        $params = self::substitute(self::decodeMap(SysConfig::getString('sms.params')), [
            '{mobile}' => $mobile,
            '{code}'   => $code,
            '{sign}'   => $signName,
        ]);
        $headers = self::decodeMap(SysConfig::getString('sms.headers'));

        $url = SysConfig::getString('sms.gateway_url');
        if ($method === 'GET' && $params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        try {
            $curl = curl_init($url);
            if ($curl === false) {
                return false;
            }

            $headerLines = [];
            $hasContentType = false;
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . self::scalar($value);
                if (strtolower((string)$name) === 'content-type') {
                    $hasContentType = true;
                }
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CUSTOMREQUEST  => $method,
            ];

            if ($method === 'POST') {
                if (!$hasContentType) {
                    $headerLines[] = 'Content-Type: application/json';
                }
                $options[CURLOPT_POSTFIELDS] = (string)json_encode($params, JSON_UNESCAPED_UNICODE);
            }
            if ($headerLines !== []) {
                $options[CURLOPT_HTTPHEADER] = $headerLines;
            }

            curl_setopt_array($curl, $options);
            curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($curl);
            curl_close($curl);

            if ($status < 200 || $status >= 300) {
                Log::warning('sms send failed: http ' . $status . ' ' . $error);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('sms send failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 解析配置里的 JSON 映射（对象）；非法 JSON 返回空数组。
     *
     * @return array<string,mixed>
     */
    public static function decodeMap(string $json): array
    {
        $decoded = json_decode(trim($json), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 递归替换参数模板里的占位符（支持嵌套对象 / 数组）。
     *
     * @param array<string,mixed>  $params
     * @param array<string,string> $replace
     * @return array<string,mixed>
     */
    public static function substitute(array $params, array $replace): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = self::substitute($value, $replace);
                continue;
            }
            if (is_string($value)) {
                $params[$key] = strtr($value, $replace);
            }
        }

        return $params;
    }

    private static function scalar(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string)$value;
        }

        return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}