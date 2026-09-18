<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Throwable;
use Webman\Http\Request;

/** JWT 签发 / 校验。 */
final class TokenService
{
    private const ALG = 'HS256';

    public static function secret(): string
    {
        $secret = config('plugin.cccms.auth.secret', '');
        if (!is_string($secret) || $secret === '') {
            throw new ApiException('JWT secret 未配置', 500);
        }
        return $secret;
    }

    /** 令牌有效期：优先取后台配置（security.token_ttl），回退到配置文件 */
    public static function ttl(): int
    {
        $ttl = SysConfig::getInt('security.token_ttl', (int)config('plugin.cccms.auth.ttl', 604800));
        return $ttl > 0 ? $ttl : 604800;
    }

    /**
     * 签发 token。
     *
     * @param int                   $userId
     * @param array<string,mixed>   $claims
     * @return array{token:string,jti:string,expires_in:int,expires_at:int}
     */
    public static function issue(int $userId, array $claims = []): array
    {
        $now = time();
        $payload = array_merge($claims, [
            'iss' => 'cccms',
            'sub' => (string)$userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::ttl(),
            'jti' => bin2hex(random_bytes(16)),
        ]);

        return [
            'token'      => JWT::encode($payload, self::secret(), self::ALG),
            // 回传 jti：在线会话登记与登出黑名单都要用它（JWT 解出来也一样，这里省一次解码）
            'jti'        => (string)$payload['jti'],
            'expires_in' => self::ttl(),
            'expires_at' => $now + self::ttl(),
        ];
    }

    /**
     * 从请求头解析 Bearer 令牌。
     *
     * 同时供 `CheckLogin`（鉴权）与 `AuthController::logout`（登出）使用，
     * 避免同一段正则散落两处。
     */
    public static function fromRequest(Request $request): ?string
    {
        $auth = $request->header('Authorization', '');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * 校验并返回 claims；失败抛 ApiException(401)。
     *
     * @return array<string,mixed>
     */
    public static function verify(string $token): array
    {
        try {
            return (array)JWT::decode($token, new Key(self::secret(), self::ALG));
        } catch (ExpiredException) {
            throw new ApiException('登录凭证已过期', 401);
        } catch (SignatureInvalidException) {
            throw new ApiException('登录凭证无效', 401);
        } catch (BeforeValidException) {
            throw new ApiException('登录凭证尚未生效', 401);
        } catch (Throwable) {
            throw new ApiException('登录凭证无效', 401);
        }
    }
}
