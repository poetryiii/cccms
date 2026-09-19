<?php

declare(strict_types=1);

use plugin\cccms\support\ApiException;
use plugin\cccms\support\PasswordPolicy;

/** 返回拒绝原因；通过时返回空串（便于断言「拒绝且原因可读」） */
$reject = static function (string $password, array $identity = []): string {
    try {
        PasswordPolicy::assertValid($password, $identity);
    } catch (ApiException $e) {
        return $e->getMessage();
    }

    return '';
};

return static function () use ($reject): void {
    suite('密码策略（P1-4）');

    test('字符类别统计', function (): void {
        same(1, PasswordPolicy::classes('123456'), '纯数字应为 1 类');
        same(2, PasswordPolicy::classes('abc123'), '小写 + 数字应为 2 类');
        same(3, PasswordPolicy::classes('Abc123'), '大小写 + 数字应为 3 类');
        same(4, PasswordPolicy::classes('Abc123!'), '四类齐全应为 4');
    });

    test('弱口令黑名单：常见口令被拒绝且原因可读', function () use ($reject): void {
        // 取值都需 ≥ 最小长度，否则会先被长度下限拦下、测不到黑名单本身
        foreach (['123456', 'password', 'admin123', 'qwerty', 'cccms123', 'woaini1314'] as $weak) {
            $reason = $reject($weak);
            ok($reason !== '', "{$weak} 应被拒绝");
            contains('过于简单', $reason, "{$weak} 的拒绝原因应指向弱口令");
        }
    });

    test('长度上限：超长口令被拒绝（避免放大 bcrypt 开销）', function () use ($reject): void {
        $reason = $reject(str_repeat('Aa1!', 20));
        ok($reason !== '', '80 位口令应被拒绝');
        contains('不能超过', $reason);
    });

    test('长度下限：过短口令被拒绝', function () use ($reject): void {
        contains('至少', $reject('Ab1!'), '4 位口令应因长度不足被拒绝');
    });

    test('身份信息：密码不得与用户名/昵称/邮箱相同或包含（且短身份不误报）', function () use ($reject): void {
        // 身份取值避开内置弱口令黑名单，否则会先被黑名单拦下、测不到身份逻辑
        contains('不能与用户名相同', $reject('zhangwei', ['username' => 'zhangwei']));
        contains('不能包含用户名', $reject('zhangwei2026', ['username' => 'zhangwei']));
        contains('不能包含昵称', $reject('XiaoMing2026', ['nickname' => 'xiaoming']));
        // 中文字符串要能用（身份需 ≥ 4 个字符才会进入包含判断）
        contains('不能包含用户名', $reject('超级管理员2026', ['username' => '超级管理员']));
        // 短于 4 字符的身份信息不做包含判断，避免高频误报
        same('', $reject('Abc123!@#', ['nickname' => '小明']), '三字昵称不应触发包含拒绝');
    });

    test('字符类别要求：不满足类别数被拒绝，满足则通过', function () use ($reject): void {
        // 默认要求 2 类：9 位纯小写虽够长、也不在黑名单，仍应因单一类别被拒绝
        // 通过样例同样要避开黑名单，否则测的是黑名单而不是类别规则
        contains('大写字母', $reject('abcdefghi'), '单一类别应被拒绝');
        same('', $reject('Abc456def'), '大小写 + 数字应通过');
        same('', $reject('wxyz9876'), '小写 + 数字应通过');
        same('', $reject('Abcdef1!'), '四类齐全应通过');
    });
};