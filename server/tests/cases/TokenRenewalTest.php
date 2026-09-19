<?php

declare(strict_types=1);

use plugin\cccms\support\TokenService;

/**
 * 令牌滑动续期（P1-3）。
 *
 * 只测**判定逻辑**：`CheckLogin` 的续期动作依赖真实请求与 Redis，
 * 由集成链路覆盖；这里保证「什么时候该续期」这条边界不会被改错。
 */
return static function (): void {
    suite('令牌滑动续期（P1-3）');

    $claims = static fn (int $leftSeconds): array => ['exp' => time() + $leftSeconds];

    test('剩余有效期充足时不续期', function () use ($claims): void {
        $ttl   = TokenService::ttl();
        $third = (int)ceil($ttl / 3);

        ok(!TokenService::shouldRenew($claims($ttl)), '刚签发的令牌不应续期');
        ok(!TokenService::shouldRenew($claims($third + 60)), '剩余超过 TTL 的 1/3 不应续期');
        ok(!TokenService::shouldRenew($claims($third)), '恰好等于 1/3 不续期（边界取「小于」）');
    });

    test('剩余有效期不足 TTL 的 1/3 时续期', function () use ($claims): void {
        $ttl   = TokenService::ttl();
        $third = (int)ceil($ttl / 3);

        ok(TokenService::shouldRenew($claims(max(1, $third - 1))), '低于 1/3 应续期');
        ok(TokenService::shouldRenew($claims(1)), '即将过期应续期');
    });

    test('已过期 / 缺 exp 不续期（交由 401 处理）', function (): void {
        ok(!TokenService::shouldRenew(['exp' => time() - 1]), '已过期不应续期');
        ok(!TokenService::shouldRenew(['exp' => 0]), 'exp=0 不应续期');
        ok(!TokenService::shouldRenew([]), '缺少 exp 不应续期');
    });

    test('兜底 TTL 为 2 小时（缩短 XSS 有效窗口，体验由续期兜住）', function (): void {
        same(7200, TokenService::DEFAULT_TTL);
        ok(TokenService::ttl() > 0, 'ttl() 应始终返回正值');
    });
};