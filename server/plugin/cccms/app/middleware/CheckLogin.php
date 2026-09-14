<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\PermissionMeta;
use plugin\cccms\support\SessionGuard;
use plugin\cccms\support\SysConfig;
use plugin\cccms\support\TokenService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 认证：解析 token → 从 DB 构建 $request->user（权限实时加载）；#[NoLogin] 跳过。 */
class CheckLogin implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $controller = $request->controller;
        if (!is_string($controller) || $controller === '') {
            return $handler($request);
        }

        $meta = PermissionMeta::of($controller, (string)$request->action);

        if (!$meta['noLogin']) {
            $token = self::bearerToken($request);
            if ($token === null) {
                throw new ApiException('未登录', 401);
            }
            $claims = TokenService::verify($token);
            $userId = (int)($claims['sub'] ?? 0);

            // TODO: 权限集合改走 Redis 缓存 + 版本号失效（见 README 3.7）
            $user = $userId > 0 ? AuthService::buildContext($userId) : null;
            if ($user === null) {
                throw new ApiException('登录凭证无效或用户已失效', 401);
            }
            $request->user = $user;

            // 维护模式：把「此前签发的令牌全部失效」这条分界线推上去（幂等，只需一次）。
            // 服务端发 401 而不是 503，是为了让前端走既有的 401 逻辑：
            // 清 token → 提示 → 回登录页；登录页会展示维护公告，非超管也登不进来。
            if (SysConfig::getBool('system.maintenance', false) && SessionGuard::cutoff() === 0) {
                SessionGuard::cut();
            }

            // 超管豁免，否则维护期间没人能进系统
            if (!$user->isSuperAdmin() && SessionGuard::isStale((int)($claims['iat'] ?? 0))) {
                throw new ApiException(self::reauthMessage(), 401);
            }
        }

        return $handler($request);
    }

    /** 被闸门作废时给用户的提示：维护期间说维护，平时说会话失效 */
    private static function reauthMessage(): string
    {
        if (SysConfig::getBool('system.maintenance', false)) {
            return SysConfig::getString('system.maintenance_notice', '系统维护中，请稍后访问')
                ?: '系统维护中，请稍后访问';
        }

        return '登录状态已失效，请重新登录';
    }

    private static function bearerToken(Request $request): ?string
    {
        $auth = $request->header('Authorization', '');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return null;
    }
}
