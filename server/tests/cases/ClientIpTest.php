<?php

declare(strict_types=1);

use plugin\cccms\support\ClientIp;

/**
 * R-01：反代场景下的真实 IP 溯源。
 *
 * 只测纯函数（`pick` / `parseChain` / `match` / `isTrusted`）——
 * 它们就是「取最右非可信 IP」算法的全部，`resolve()` 只是取直连 IP + XFF 后调 `pick()`。
 */
return static function (): void {
    suite('真实 IP 溯源（R-01 反代场景）');

    test('CIDR 匹配：单 IP、网段、IPv6 与非法输入', function (): void {
        ok(ClientIp::match('10.0.0.5', '10.0.0.0/8'), '10.0.0.5 应落在 10.0.0.0/8');
        ok(ClientIp::match('10.1.2.3', '10.1.2.3'), '单 IP 形式应精确匹配');
        ok(!ClientIp::match('11.0.0.5', '10.0.0.0/8'), '11.0.0.5 不应落在 10.0.0.0/8');
        ok(ClientIp::match('192.168.1.200', '192.168.1.0/24'), '/24 边界内应命中');
        ok(!ClientIp::match('192.168.2.1', '192.168.1.0/24'), '/24 边界外不应命中');
        ok(!ClientIp::match('10.0.0.5', '10.0.0.0/33'), '前缀超范围应视为非法');

        // IPv6
        ok(ClientIp::match('2001:db8::1', '2001:db8::/32'), 'IPv6 /32 应命中');
        ok(!ClientIp::match('2001:db9::1', '2001:db8::/32'), 'IPv6 前缀不同不应命中');
        // IPv4 与 IPv6 不可混比
        ok(!ClientIp::match('10.0.0.5', '::ffff:10.0.0.0/104'), '地址族不同不应命中');

        ok(!ClientIp::match('not-an-ip', '10.0.0.0/8'), '非法 IP 不应命中');
        ok(!ClientIp::match('10.0.0.5', ''), '空 CIDR 不应命中');
    });

    test('XFF 解析：丢弃非法条目、剥离端口，保持「客户端 → 服务端」顺序', function (): void {
        same(['1.2.3.4', '10.0.0.5'], ClientIp::parseChain('1.2.3.4, 10.0.0.5'));

        // 带端口的写法（Nginx 有时会写 $remote_addr:$remote_port）
        same(['1.2.3.4'], ClientIp::parseChain('1.2.3.4:54321'));
        same(['2001:db8::1', '10.0.0.5'], ClientIp::parseChain('[2001:db8::1]:443, 10.0.0.5'));

        // 攻击者插入垃圾串不应顶掉有效条目
        same(['1.2.3.4'], ClientIp::parseChain('evil, 1.2.3.4, unknown'));

        same([], ClientIp::parseChain(''));
        same([], ClientIp::parseChain('   '));
    });

    test('未配置可信代理时：一律用直连 IP，转发头不可伪造', function (): void {
        same('203.0.113.9', ClientIp::pick('203.0.113.9', ['1.1.1.1'], []));
    });

    test('直连来源不可信（绕过网关直连）时：忽略 XFF', function (): void {
        // 攻击者直连应用端口，带上伪造的 XFF
        same('203.0.113.9', ClientIp::pick('203.0.113.9', ['1.2.3.4'], ['172.18.0.0/16']));
    });

    test('可信代理下取最右非可信 IP：左侧伪造条目被跳过', function (): void {
        $trusted = ['172.18.0.0/16'];

        // 单跳：代理追加的真实客户端 IP
        same('1.2.3.4', ClientIp::pick('172.18.0.1', ['1.2.3.4'], $trusted));

        // 攻击者自造 `9.9.9.9` 放在左边，代理把真实出口追加在右边 → 取右边的真实 IP
        same('1.2.3.4', ClientIp::pick('172.18.0.1', ['9.9.9.9', '1.2.3.4'], $trusted));

        // 两层可信代理：跳过最右侧代理，返回其左侧的真实客户端
        same('1.2.3.4', ClientIp::pick('172.18.0.1', ['1.2.3.4', '172.18.0.9'], $trusted));

        // 整条链都是可信代理：退回最早的记录
        same('172.18.0.7', ClientIp::pick('172.18.0.1', ['172.18.0.7', '172.18.0.9'], $trusted));

        // 无 XFF：用直连 IP
        same('172.18.0.1', ClientIp::pick('172.18.0.1', [], $trusted));
    });
};
