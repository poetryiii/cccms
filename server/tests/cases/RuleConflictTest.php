<?php

declare(strict_types=1);

use plugin\cccms\support\RuleConflict;

return static function (): void {
    suite('数据权限规则体检');

    /** 构造一条行级规则（绑定同一个用户，保证「必然同时命中同一批人」） */
    $rowRule = static fn (int $id, string $operator, string $value, int $userId = 7): array => [
        'id'          => $id,
        'name'        => '规则' . $id,
        'action'      => 'row',
        'user_id'     => $userId,
        'post_id'     => 0,
        'role_id'     => 0,
        'dept_ids'    => '[]',
        'bind_mode'   => 'or',
        'table_name'  => 'user',
        'field'       => 'id',
        'operator'    => $operator,
        'value'       => $value,
        'value_type'  => 'static',
    ];

    /** 构造一条字段级规则 */
    $fieldRule = static fn (int $id, string $action, int $userId = 7): array => [
        'id'          => $id,
        'name'        => '字段规则' . $id,
        'action'      => $action,
        'user_id'     => $userId,
        'post_id'     => 0,
        'role_id'     => 0,
        'dept_ids'    => '[]',
        'bind_mode'   => 'or',
        'table_name'  => 'user',
        'field'       => 'phone',
        'operator'    => '=',
        'value'       => '',
        'value_type'  => 'static',
    ];

    $types = static fn (array $findings): array => array_values(array_unique(array_column($findings, 'type')));

    test('条件互斥被报为 row_unsat（id=1 且 id!=1）', function () use ($rowRule, $types): void {
        $findings = RuleConflict::check([
            $rowRule(1, '=', '1'),
            $rowRule(2, '!=', '1'),
        ]);
        ok(in_array(RuleConflict::ROW_UNSAT, $types($findings), true), '应报出行级条件互斥');
    }, true);

    test('三条规则求交为空也能抓到（in 1,2 + in 2,3 + != 2）', function () use ($rowRule, $types): void {
        $findings = RuleConflict::check([
            $rowRule(1, 'in', '1,2'),
            $rowRule(2, 'in', '2,3'),
            $rowRule(3, '!=', '2'),
        ]);
        ok(in_array(RuleConflict::ROW_UNSAT, $types($findings), true), '应报出行级条件互斥');
    }, true);

    test('有交集时不误报 row_unsat（in 1,2 + in 2,3）', function () use ($rowRule, $types): void {
        $findings = RuleConflict::check([
            $rowRule(1, 'in', '1,2'),
            $rowRule(2, 'in', '2,3'),
        ]);
        ok(!in_array(RuleConflict::ROW_UNSAT, $types($findings), true), '不该误报');
    }, true);

    test('绑定不同用户时不比较（宁漏不误）', function () use ($rowRule, $types): void {
        $findings = RuleConflict::check([
            $rowRule(1, '=', '1', 7),
            $rowRule(2, '!=', '1', 8),
        ]);
        ok(!in_array(RuleConflict::ROW_UNSAT, $types($findings), true), '不同绑定项不该下结论');
    }, true);

    test('同字段多个字段级动作被报为 field_dup', function () use ($fieldRule, $types): void {
        $findings = RuleConflict::check([
            $fieldRule(1, 'mask'),
            $fieldRule(2, 'encrypt'),
        ]);
        ok(in_array(RuleConflict::FIELD_DUP, $types($findings), true), '应报出同字段多动作');
    }, true);

    test('两次 mask 是幂等的，不报 field_dup', function () use ($fieldRule, $types): void {
        $findings = RuleConflict::check([
            $fieldRule(1, 'mask'),
            $fieldRule(2, 'mask'),
        ]);
        ok(!in_array(RuleConflict::FIELD_DUP, $types($findings), true), '两次 mask 不该报');
    }, true);

    test('byRule 能把体检结果挂回规则 id', function () use ($rowRule): void {
        $findings = RuleConflict::check([
            $rowRule(1, '=', '1'),
            $rowRule(2, '!=', '1'),
        ]);
        $byRule = RuleConflict::byRule($findings);
        ok(isset($byRule[1]) && isset($byRule[2]), '两条规则都应挂到体检结果');
    }, true);

    test('未登记受控表被报为 not_guarded', function () use ($rowRule, $types): void {
        $rule = $rowRule(1, '=', '1');
        $rule['table_name'] = 'table_never_registered';
        $findings = RuleConflict::check([$rule]);
        ok(in_array(RuleConflict::NOT_GUARDED, $types($findings), true), '应报出未登记受控表');
    }, true);
};
