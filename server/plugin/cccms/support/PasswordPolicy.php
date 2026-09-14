<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 密码策略。
 *
 * 新增用户 / 重置密码 / 个人中心改密三处共用，避免同一条策略写三遍后走偏。
 */
final class PasswordPolicy
{
    /** 长度下限取自 security.password_min_length */
    public static function assertValid(string $password): void
    {
        $min = max(1, SysConfig::getInt('security.password_min_length', 6));
        if (mb_strlen($password) < $min) {
            throw new ApiException("密码至少 {$min} 位", 422);
        }
    }
}
