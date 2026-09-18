<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\Captcha;
use plugin\cccms\support\Cipher;
use plugin\cccms\support\LoginThrottle;
use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\TokenService;
use plugin\cccms\support\UserContext;

/** 登录认证逻辑。 */
final class AuthLogic
{
    /**
     * 账号密码登录，返回 token 与用户信息。
     *
     * 成功与失败都会写入登录日志（`sys_log`，type='login'）——失败记录是审计的重点。
     */
    public static function login(
        string $username,
        string $password,
        string $captcha = '',
        string $captchaId = '',
    ): array {
        try {
            $result = self::attempt($username, $password, $captcha, $captchaId);
        } catch (ApiException $e) {
            LogLogic::recordLogin(0, $username, false, $e->getMessage());
            throw $e;
        }

        LogLogic::recordLogin((int)($result['user']['id'] ?? 0), $username, true, '登录成功');

        return $result;
    }

    /**
     * 登录的实际执行体：失败一律抛 `ApiException`，由 `login()` 统一落登录日志。
     *
     * @return array{token:array<string,mixed>,user:array<string,mixed>}
     */
    private static function attempt(
        string $username,
        string $password,
        string $captcha,
        string $captchaId,
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

        $ip = (string)(request()->getRealIp() ?: '');

        // 更新登录信息（登录链路同样没有当前用户上下文，显式跳出作用域）
        User::withoutGlobalScope()->where('id', $user['id'])->update([
            'login_time' => date('Y-m-d H:i:s'),
            'login_ip'   => $ip,
        ]);

        $token = TokenService::issue((int)$user['id']);

        // 「强制下线」是按用户记录的签发时间分界线（`iat <= cutoff` 即失效）。
        // 登录成功必须清除它，否则与该分界线**同一秒**签发的新令牌会被立刻判成失效。
        TokenBlacklist::clearUser((int)$user['id']);

        // 登记在线会话，供「在线用户 / 强制下线」使用（Redis 不可用时静默降级）
        OnlineSession::register(
            (int)$user['id'],
            (string)$user['username'],
            (string)$user['nickname'],
            (string)$token['jti'],
            $ip,
            (string)$request->header('user-agent', ''),
        );

        return [
            'token' => $token,
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

    /**
     * 登出：作废当前令牌（写入 jti 黑名单）+ 移除在线会话。
     *
     * 令牌是无状态的，只靠前端丢弃 token 并不能阻止旧 token 在有效期内继续使用，
     * 因此这里把 jti 记入 Redis 黑名单（TTL = 剩余有效期），`CheckLogin` 每次校验。
     *
     * @param array<string,mixed> $claims 当前令牌的 claims（由控制器解析后传入）
     */
    public static function logout(UserContext $user, array $claims = []): void
    {
        $jti = (string)($claims['jti'] ?? '');
        TokenBlacklist::revoke($jti, (int)($claims['exp'] ?? 0));
        OnlineSession::remove($jti);
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
