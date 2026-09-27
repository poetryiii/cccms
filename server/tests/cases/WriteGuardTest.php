<?php

declare(strict_types=1);

use plugin\cccms\support\FilterInput;
use plugin\cccms\support\WriteGuardChecker;

/**
 * R-02：写入口字段白名单（防 Mass Assignment）。
 */
return static function (): void {
    suite('写入口字段白名单（R-02）');

    test('FilterInput::only 只保留白名单键，其余一律丢弃', function (): void {
        $raw = [
            'username'  => 'alice',
            'nickname'  => 'Alice',
            'tenant_id' => 999,        // 越权字段
            'create_by' => 1,          // 系统维护字段
            'id'        => 12345,      // 主键
        ];

        $out = FilterInput::only($raw, ['username', 'nickname']);

        same(['username' => 'alice', 'nickname' => 'Alice'], $out, '白名单外的键必须被丢弃');
    });

    test('白名单不过滤值的类型与顺序，空白名单丢弃全部', function (): void {
        same([], FilterInput::only(['a' => 1, 'b' => 2], []), '空白名单应丢弃所有字段');

        $raw = ['b' => 0, 'a' => false];
        $out = FilterInput::only($raw, ['a', 'b']);
        // 保序（按原数组顺序，不是白名单顺序），且 0 / false 不被当成空值
        same(['b' => 0, 'a' => false], $out, '应保序且不丢 falsy 值');
    });

    test('主代码中所有写方法都已声明白名单（静态校验 0 错误）', function (): void {
        $errors = array_filter(
            WriteGuardChecker::check(),
            static fn (array $finding): bool => $finding['level'] === 'error'
        );

        same(
            [],
            array_map(static fn (array $f): string => $f['file'] . ':' . $f['line'] . ' ' . $f['msg'], $errors),
            '存在未声明字段白名单的写方法（跑 php webman cccms:write-guard-check 查看）'
        );
    });
};
