<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 密码策略。
 *
 * 新增用户 / 编辑用户 / 重置密码 / 个人中心改密四处共用，避免同一条策略写四遍后走偏。
 *
 * 规则（阈值全部来自 sys_config，保存后立即生效）：
 *   security.password_min_length  长度下限，默认 6
 *   security.password_max_length  长度上限，默认 64 —— 没有上限时超长口令会放大 bcrypt 开销，
 *                                 构成「一个请求打满一个 worker」的低成本 DoS
 *   security.password_strength    需包含的字符类别数（大写/小写/数字/符号），0 = 不要求
 *   （内置弱口令黑名单与「不得与用户名等身份信息相同」为固定规则，不提供关闭开关）
 *
 * 黑名单是**内置的**而不是可配置项：能改黑名单等于能关掉这条防线。
 */
final class PasswordPolicy
{
    /**
     * 内置弱口令黑名单（比较前统一转小写）。
     *
     * 取自历年泄露榜单头部，并补充本项目 / 中文环境的常见口令（含 seed 的默认密码 admin123）。
     * 刻意不做成「Top 1000」全量清单：全量清单体积大、收益递减，真正高频的就是这一批；
     * 其余靠「不得包含用户名」与字符类别要求兜住。
     */
    private const WEAK = [
        '123456', '1234567', '12345678', '123456789', '1234567890', '12345678910',
        '111111', '000000', '666666', '888888', '123123', '112233', '121212',
        'qwerty', 'qwerty123', 'qwertyuiop', 'asdfgh', 'asdfghjkl', 'zxcvbn', 'zxcvbnm',
        'abc123', 'abcd1234', 'a123456', 'a123456789', 'aa123456', '123qwe', 'qwe123',
        'password', 'passw0rd', 'p@ssw0rd', 'password1', 'password123', 'passwd',
        'admin', 'admin123', 'administrator', 'root', 'root123', 'toor',
        'iloveyou', 'letmein', 'welcome', 'welcome1', 'monkey', 'dragon', 'sunshine',
        'superman', 'master', 'shadow', 'football', 'baseball', 'princess', 'whatever',
        'test', 'test123', 'guest', 'demo', 'demo123', 'temp123', 'changeme',
        'cccms', 'cccms123', 'cccms@123', 'system', 'manager', 'operator',
        'woaini', 'woaini1314', '5201314', '1314520', 'woaini520', 'a5201314',
        'zhangsan', 'lisi', 'wangwu', '123456a', '123456abc', 'abc123456',
    ];

    /**
     * @param array<string,mixed> $context 身份信息（username / nickname / email），
     *                                     用于拒绝「密码与身份相同或包含身份」
     */
    public static function assertValid(string $password, array $context = []): void
    {
        $max = SysConfig::getInt('security.password_max_length', 64);
        if ($max > 0 && mb_strlen($password) > $max) {
            throw new ApiException("密码不能超过 {$max} 位", 422);
        }

        $min = max(1, SysConfig::getInt('security.password_min_length', 6));
        if (mb_strlen($password) < $min) {
            throw new ApiException("密码至少 {$min} 位", 422);
        }

        $lower = mb_strtolower($password);

        if (in_array($lower, self::WEAK, true)) {
            throw new ApiException('密码过于简单，请勿使用常见口令', 422);
        }

        foreach (self::identities($context) as $label => $value) {
            if ($lower === $value) {
                throw new ApiException("密码不能与{$label}相同", 422);
            }
            // 短于 4 个字符的身份信息（如两字昵称）不做包含判断，否则误报率过高
            if (mb_strlen($value) >= 4 && str_contains($lower, $value)) {
                throw new ApiException("密码不能包含{$label}", 422);
            }
        }

        $need = min(4, SysConfig::getInt('security.password_strength', 2));
        if ($need > 0 && self::classes($password) < $need) {
            throw new ApiException("密码需包含大写字母、小写字母、数字、符号中的 {$need} 类", 422);
        }
    }

    /** 已包含的字符类别数（大写 / 小写 / 数字 / 符号） */
    public static function classes(string $password): int
    {
        $count = 0;
        foreach (['/[a-z]/', '/[A-Z]/', '/\d/', '/[^A-Za-z0-9]/'] as $pattern) {
            if (preg_match($pattern, $password) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 身份信息（小写），键为提示语里的名称。
     *
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private static function identities(array $context): array
    {
        $map = [];
        foreach (['username' => '用户名', 'nickname' => '昵称', 'email' => '邮箱'] as $key => $label) {
            $value = mb_strtolower(trim((string)($context[$key] ?? '')));
            if ($value !== '') {
                $map[$label] = $value;
            }
        }

        return $map;
    }
}
