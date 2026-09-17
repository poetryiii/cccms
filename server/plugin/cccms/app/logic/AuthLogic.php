<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\Captcha;
use plugin\cccms\support\Cipher;
use plugin\cccms\support\LoginThrottle;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\TokenService;
use plugin\cccms\support\UserContext;

/** 登录认证逻辑。 */
final class AuthLogic
{
    /** 账号密码登录，返回 token 与用户信息。 */
    public static function login(
        string $username,
        string $password,
        string $captcha = '',
        string $captchaId = '',
    ): array {
        if ($username === '' || $password === '') {
            throw new ApiException('请输入用户名和密码', 422);
        }

        // ① 失败锁定：先判断，避免锁定期间仍可用于撞库
        LoginThrottle::assertNotLocked($username);

        // ② 图形验证码（由 security.login_captcha 控制，默认关闭）
        if (SysConfig::getBool('security.login_captcha', false) && !Captcha::verify($captchaId, $captcha)) {
            throw new ApiException('验证码错误或已过期', 422);
        }

        // 已进回收站的账号不能登录（用户名仍被占用，但登录入口直接当作不存在）。
        // 显式跳出数据权限：此时还没有当前用户，数据范围本身就要靠这行数据算出来。
        $user = User::withoutGlobalScope()->where('username', $username)->find();
        if (!$user || !password_verify($password, (string)$user['password'])) {
            LoginThrottle::recordFailure($username);
            throw new ApiException('用户名或密码错误' . self::attemptTip($username), 422);
        }
        if ((int)$user['status'] !== 1) {
            throw new ApiException('账号已被禁用', 403);
        }

        $context = AuthService::buildContext((int)$user['id']);
        if ($context === null) {
            throw new ApiException('账号角色异常，请联系管理员', 403);
        }

        // ③ 维护模式：只允许超管登录（前端也会展示维护公告）
        if (SysConfig::getBool('system.maintenance', false) && !$context->isSuperAdmin()) {
            throw new ApiException(self::maintenanceNotice(), 503);
        }

        LoginThrottle::clear($username);

        // 更新登录信息（登录链路同样没有当前用户上下文，显式跳出作用域）
        User::withoutGlobalScope()->where('id', $user['id'])->update([
            'login_time' => date('Y-m-d H:i:s'),
            'login_ip'   => request()->getRealIp() ?: '',
        ]);

        return [
            'token' => TokenService::issue((int)$user['id']),
            'user'  => self::profile($context),
        ];
    }

    /** 用户信息（含 encrypt 字段解密所需的密钥；未配置该密钥时不返回，避免 /me 直接 500）。 */
    public static function profile(UserContext $user): array
    {
        $data = $user->toArray();
        if (Cipher::configured()) {
            $data['crypto_key'] = Cipher::publicKey();
        }
        return $data;
    }

    /** 登出（一期：客户端丢弃 token；Redis 黑名单见 TODO）。 */
    public static function logout(UserContext $user): void
    {
        // TODO: 将 jti 加入 Redis 黑名单
    }

    /** 密码错误时附带剩余次数提示（未启用锁定时不提示） */
    private static function attemptTip(string $username): string
    {
        if (SysConfig::getInt('security.login_fail_limit', 5) <= 0) {
            return '';
        }
        $left = LoginThrottle::remainingAttempts($username);
        return $left > 0 ? "，还可尝试 {$left} 次" : '';
    }

    private static function maintenanceNotice(): string
    {
        return SysConfig::getString('system.maintenance_notice', '系统维护中，请稍后访问') ?: '系统维护中，请稍后访问';
    }
}
