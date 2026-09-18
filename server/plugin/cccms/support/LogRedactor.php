<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 日志敏感字段递归脱敏（写入与归档共用）。
 *
 * 为什么抽出来：写日志时脱敏一次，归档冷数据前必须**再**脱敏一次——
 * 历史记录可能早于脱敏规则的补充，直接归档等于把密码 / token 固化进对象存储。
 * 两条路径共用同一份规则，避免各自维护后漂移。
 */
final class LogRedactor
{
    /** 需要脱敏的字段（小写比对，递归生效） */
    private const SENSITIVE = [
        'password', 'old_password', 'new_password', 'password_confirm',
        'token', 'access_token', 'refresh_token', 'secret',
    ];

    /**
     * 递归脱敏：密码 / token 这类字段可能藏在嵌套结构里。
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 3) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string)$key), self::SENSITIVE, true)) {
                $data[$key] = '******';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }

    /**
     * 对 JSON 字符串字段（sys_log.params / result）脱敏。
     *
     * 只有能解析成数组才处理；纯文本 / 非 JSON 原样返回——文本里的密码无法靠 key
     * 识别，强行正则替换会误伤正常内容。
     */
    public static function redactJson(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }

        return (string)json_encode(
            self::redact($decoded),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
