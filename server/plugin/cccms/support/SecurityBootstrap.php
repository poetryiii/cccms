<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use RuntimeException;
use support\Log;
use Webman\Bootstrap;

/**
 * 启动期安全自检（fail-closed）。
 *
 * 把「密钥配置疏漏」挡在运行时之前：`JWT_SECRET` 不满足要求时**直接终止进程**，
 * 而不是带着公开的默认密钥对外提供鉴权——起不来会被立刻发现，被越权不会。
 *
 * - `JWT_SECRET`：必须已注入、长度 >= 32 字节，且不等于旧版仓库中公开的示例值。
 * - `DATA_ENCRYPT_KEY`：为空或等于旧版示例值时**只告警不终止**。原因是该密钥
 *   一旦更换，历史加密字段将永久无法解密（已确认不做密钥轮换），
 *   硬失败会把「密钥配置疏漏」升级为「数据不可读」，代价不可逆。
 *
 * 本地开发：在 `server/.env` 中注入随机值即可通过（见 `.env.example`）。
 */
class SecurityBootstrap implements Bootstrap
{
    /**
     * 历史上曾作为「兜底默认值」写进仓库的密钥，一律禁止再使用。
     * 它们已随源码公开，继续使用等于没有密钥。
     */
    private const FORBIDDEN = [
        'cccms-local-dev-secret-0123456789abcdef-0123456789abcdef-0123456789abcdef',
        'cccms-local-dev-crypto-key-0123456789abcdef',
    ];

    public static function start($worker): void
    {
        $secret = (string)(getenv('JWT_SECRET') ?: '');

        if ($secret === '') {
            throw new RuntimeException(
                'JWT_SECRET 未配置，服务拒绝启动。'
                . '请在 server/.env 中注入随机密钥（HS256 要求 >= 32 字节）：'
                . 'php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (strlen($secret) < TokenService::MIN_SECRET_LENGTH) {
            throw new RuntimeException(
                'JWT_SECRET 长度不足 ' . TokenService::MIN_SECRET_LENGTH . ' 字节，服务拒绝启动。'
                . '请重新生成随机密钥：php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (in_array($secret, self::FORBIDDEN, true)) {
            throw new RuntimeException(
                'JWT_SECRET 仍是仓库中公开的历史默认值，服务拒绝启动。'
                . '该值已随源码公开，等同于没有密钥，任何人都能伪造任意用户令牌。'
            );
        }

        // 只告警：更换该密钥会让历史加密字段永久不可解密，不能以「不可逆的数据损失」换取配置纠正。
        $dataKey = (string)(getenv('DATA_ENCRYPT_KEY') ?: '');
        if ($dataKey === '' || in_array($dataKey, self::FORBIDDEN, true)) {
            Log::warning(
                '[cccms] DATA_ENCRYPT_KEY 未配置或仍为公开的历史默认值：'
                . '字段级 encrypt 仅能防「数据库裸读」，请尽快注入随机密钥'
                . '（注意：更换后历史加密字段将无法解密，需先备份或确认无历史数据）。'
            );
        }
    }
}
