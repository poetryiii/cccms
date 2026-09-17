<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 数据权限规则体检：把「会让人什么都看不到」的规则组合挑出来。
 *
 * 为什么需要它：自定义行级规则只能**收窄**范围（往 where 里加条件），没有「允许 / 拒绝」这种极性，
 * 多条命中同一个人的规则是 **AND 叠加**。所以两条语义相反的规则同时命中时，结果不是
 * 「以某条为主」，而是 `WHERE id = 1 AND id != 1` —— 什么都看不到。
 *
 * 这个结果本身是 fail-closed（安全，不会误放开），但**静默**：管理员只会看到页面空白，
 * 不知道是自己配冲突了。本类负责在保存后 / 命令行里把它显式报出来。
 *
 * 判定原则：**只报可证明为空的组合，宁可漏报不误报**。
 *   - 只比较「必然同时命中同一批人」的规则：绑定签名完全相同，或其中一条无绑定（全局规则命中所有人）；
 *   - 只处理静态取值（`value_type=static`）：动态变量要按运行时用户解析，无法静态判定；
 *   - `like`、非数字的比较与区间一律跳过（判不了就不下结论）。
 *
 * 已知局限（「宁漏不误」的代价）：
 *   - 绑定不同（如「部门 A」与「岗位 P」）的两条规则也可能有人同时命中，但需要查用户全量才能判定，不检；
 *   - 目标表未登记受控表时规则本就不执行，这类只报 NOT_GUARDED，不参与冲突组合。
 */
final class RuleConflict
{
    /** 行级条件互斥：命中这些规则的人看不到任何数据 */
    public const ROW_UNSAT = 'row_unsat';

    /** 同一字段被多个字段级动作处理：按顺序叠加，而不是取「最严」 */
    public const FIELD_DUP = 'field_dup';

    /** 取值不完整：规则恒不生效（静默） */
    public const NEVER_HIT = 'never_hit';

    /** 目标表未登记受控表：规则不会执行 */
    public const NOT_GUARDED = 'not_guarded';

    /** 数值区间端点比较用的容差（规则取值都是字符串，主键/数值场景足够） */
    private const EPSILON = 0.000001;

    /**
     * 体检。
     *
     * @param  array<int,array<string,mixed>> $rules 启用中的规则（未进回收站）
     * @return array<int,array{type:string,field:string,table:string,rules:array<int,int>,names:array<int,string>,message:string}>
     */
    public static function check(array $rules): array
    {
        $guarded  = DataScope::guardedTables();
        $findings = [];

        foreach ($rules as $rule) {
            $table = self::tableOf($rule);

            // 规则绑定的表被移出受控表后，规则就不执行了（保存时校验过，这里兜住事后变更）
            if ($table !== '' && !in_array($table, $guarded, true)) {
                $findings[] = self::finding(
                    self::NOT_GUARDED,
                    [$rule],
                    $table,
                    "目标表 {$table} 未登记受控表，这条规则不会执行"
                );
            }

            $never = self::neverHitReason($rule);
            if ($never !== null) {
                $findings[] = self::finding(self::NEVER_HIT, [$rule], $table, $never);
            }
        }

        // 同批人的规则组：能抓到「三条规则两两有交集、合起来为空」这类组合
        foreach (self::scopes($rules, $guarded) as [$set, $table]) {
            foreach (self::fieldBuckets($set, $table) as $field => $bucket) {
                foreach (self::checkField($field, $bucket, $table) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        return self::dedupe($findings);
    }

    /**
     * 按规则 id 归并（列表页用：每条规则挂上与自己相关的体检结果）。
     *
     * @param  array<int,array<string,mixed>> $findings
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function byRule(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            foreach ($finding['rules'] as $id) {
                $out[(int)$id][] = $finding;
            }
        }

        return $out;
    }

    /**
     * 待比较的规则集合：同一组内的规则「必然会有共同命中的用户」。
     *
     * 分组依据是绑定签名（绑定完全相同 ⇒ 命中的是同一批人）；全局规则（无绑定）命中所有人，
     * 因此并入每个分组。再按有效目标表分桶：具体表各自的规则 + 「不限表」的规则（对所有受控表生效）。
     *
     * @param  array<int,array<string,mixed>> $rules
     * @param  array<int,string>              $guarded
     * @return array<int,array{0:array<int,array<string,mixed>>,1:string}>
     */
    private static function scopes(array $rules, array $guarded): array
    {
        $active = [];
        foreach ($rules as $rule) {
            $table = self::tableOf($rule);
            // 目标表没登记受控表 → 规则不会执行，排除出组合比较（另有 NOT_GUARDED 单独提示）
            if ($table === '' || in_array($table, $guarded, true)) {
                $active[] = $rule;
            }
        }

        $globals = array_values(array_filter($active, static fn ($r): bool => self::isGlobal($r)));

        $groups = [];
        foreach ($active as $rule) {
            if (!self::isGlobal($rule)) {
                $groups[self::signature($rule)][] = $rule;
            }
        }

        // 待比较的规则集合：每个绑定分组并入全局规则（全局规则命中所有人，与谁都有共同命中者）；
        // 全局规则自己单独一组，覆盖「绑定分组为空」或「全局规则之间才冲突」的情形。
        $sets = [$globals];
        foreach ($groups as $group) {
            $sets[] = array_merge($group, $globals);
        }

        $scopes = [];
        foreach ($sets as $set) {
            if (count($set) < 2) {
                continue;
            }

            // 具体表：集合内出现过的非空表名，每个表单独分析一次
            $tables = [];
            foreach ($set as $rule) {
                $table = self::tableOf($rule);
                if ($table !== '') {
                    $tables[$table] = true;
                }
            }
            foreach (array_keys($tables) as $table) {
                $scopes[] = [$set, (string)$table];
            }

            // 「不限表」的规则对所有受控表生效：它们之间也要比一次（此时所有成员的表名都是空的）
            $unbounded = array_values(array_filter($set, static fn ($r): bool => self::tableOf($r) === ''));
            if (count($unbounded) >= 2) {
                $scopes[] = [$unbounded, ''];
            }
        }

        return $scopes;
    }

    /**
     * 按「有效目标表」过滤后按字段分桶。
     *
     * @param  array<int,array<string,mixed>> $rules
     * @return array<string,array<int,array<string,mixed>>> 字段名 => 规则
     */
    private static function fieldBuckets(array $rules, string $table): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $bound = self::tableOf($rule);
            if ($bound !== '' && $bound !== $table) {
                continue;
            }
            $out[(string)($rule['field'] ?? '')][] = $rule;
        }

        return $out;
    }

    /**
     * 单个字段上的体检。
     *
     * @param  array<int,array<string,mixed>> $bucket
     * @return array<int,array<string,mixed>>
     */
    private static function checkField(string $field, array $bucket, string $table): array
    {
        if ($field === '' || count($bucket) < 2) {
            return [];
        }

        $rows   = array_values(array_filter($bucket, static fn ($r): bool => (string)($r['action'] ?? '') === 'row'));
        $fields = array_values(array_filter($bucket, static fn ($r): bool => (string)($r['action'] ?? '') !== 'row'));
        $out    = [];

        // ① 行级：全部条件 AND 到一起后是否还可能取到值
        if (count($rows) >= 2) {
            $conds = [];
            foreach ($rows as $rule) {
                $cond = self::constraint($rule);
                if ($cond === null) {
                    $conds = null; // 有判不了的，整体不下结论
                    break;
                }
                $conds[] = $cond;
            }

            if ($conds !== null && self::unsatReason($conds) !== null) {
                $out[] = self::finding(
                    self::ROW_UNSAT,
                    $rows,
                    $table,
                    '字段 ' . $field . ' 的条件互斥（' . implode('，', self::condTexts($rows)) . '）：'
                    . '多条行级规则是 AND 叠加，命中这些规则的用户将看不到任何数据'
                );
            }
        }

        // ② 字段级：同字段多个动作是「顺序叠加」，不是取最严
        if (count($fields) >= 2) {
            $counts   = array_count_values(array_map(static fn ($r) => (string)($r['action'] ?? ''), $fields));
            $distinct = count($counts);
            // 不同动作叠加 = 结果依赖顺序；encrypt 叠加两次 = 前端解一次拿到的还是密文。
            // 两次 mask 是幂等的，不报（避免噪音）。
            $risky = $distinct > 1 || ($counts['encrypt'] ?? 0) > 1;
            if ($risky) {
                $out[] = self::finding(
                    self::FIELD_DUP,
                    $fields,
                    $table,
                    '字段 ' . $field . ' 被多个字段级规则处理（' . implode('、', array_map(
                        static fn ($r) => (string)($r['name'] ?? '') . '=' . (string)($r['action'] ?? ''),
                        $fields
                    )) . '）：动作按规则顺序依次叠加而不是取最严，'
                    . 'mask 与 encrypt 混用会产出「被掩码的密文」'
                );
            }
        }

        return $out;
    }

    /** 规则是否命中所有人（四个绑定维度都空） */
    private static function isGlobal(array $rule): bool
    {
        return (int)($rule['user_id'] ?? 0) === 0
            && (int)($rule['post_id'] ?? 0) === 0
            && (int)($rule['role_id'] ?? 0) === 0
            && self::deptIds($rule) === [];
    }

    /**
     * 绑定签名：绑定项完全相同 ⇒ 两条规则必然有共同命中的用户，可以放进同一组比较。
     *
     * 刻意**不含 `bind_mode`**：同样绑定项下，「且」命中的集合是各绑定项的交集，
     * 「或」是并集，交集必然包含于并集 —— 所以两种组合方式命中的一定是同一批人里的
     * 子集/超集关系，仍然可比。把 bind_mode 也放进来会导致
     * 「规则1 用且、规则2 用或」被当成两批人而漏检（实测确实同时命中同一个用户）。
     *
     * 不同绑定项的规则也可能有人同时命中（例如绑「部门 A」与绑「岗位 P」），但那需要
     * 查用户全量才能判定，按「宁漏不误」原则不参与比较。
     */
    private static function signature(array $rule): string
    {
        $depts = self::deptIds($rule);
        sort($depts);

        return implode('|', [
            (int)($rule['user_id'] ?? 0),
            (int)($rule['post_id'] ?? 0),
            (int)($rule['role_id'] ?? 0),
            implode(',', $depts),
        ]);
    }

    /** @return array<int,int> */
    private static function deptIds(array $rule): array
    {
        $ids = $rule['dept_ids'] ?? null;
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        return array_values(array_filter(array_map('intval', (array)$ids), static fn ($id): bool => $id > 0));
    }

    private static function tableOf(array $rule): string
    {
        return trim((string)($rule['table_name'] ?? ''));
    }

    /**
     * 把一条行级规则转成可判定的约束；判不了返回 null（调用方整体放弃下结论）。
     *
     * @return array{op:string,value:string}|null
     */
    private static function constraint(array $rule): ?array
    {
        if (((string)($rule['value_type'] ?? 'static')) !== 'static') {
            return null; // 动态变量要按运行时用户解析
        }

        $op = strtolower(trim((string)($rule['operator'] ?? '')));
        if (!in_array($op, DataScope::ROW_OPERATORS, true)) {
            return null;
        }

        return ['op' => $op === '<>' ? '!=' : $op, 'value' => (string)($rule['value'] ?? '')];
    }

    /**
     * 约束集合是否**必然取不到值**；取得到或判不了都返回 null。
     *
     * 取值统一按字符串比较（与 DB 里的 varchar 取值一致）；只有比较符 / between 需要数字。
     *
     * @param array<int,array{op:string,value:string}> $conds
     */
    private static function unsatReason(array $conds): ?string
    {
        $allowed  = null;  // 字面量候选（= / in 的交集）
        $excluded = [];
        $ranges   = [];    // [lo, hi] 闭区间

        foreach ($conds as $cond) {
            $op = $cond['op'];
            $v  = trim($cond['value']);

            switch ($op) {
                case 'like':
                    return null; // 通配匹配判不了
                case '!=':
                    $excluded[] = $v;
                    break;
                case '=':
                    $allowed = $allowed === null ? [$v] : array_values(array_intersect($allowed, [$v]));
                    break;
                case 'in':
                    $items = self::splitList($v);
                    if ($items === []) {
                        return '「属于」的取值为空';
                    }
                    $allowed = $allowed === null ? $items : array_values(array_intersect($allowed, $items));
                    break;
                case 'between':
                    $parts = array_map('trim', explode(',', $v));
                    if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                        return null;
                    }
                    $ranges[] = [min((float)$parts[0], (float)$parts[1]), max((float)$parts[0], (float)$parts[1])];
                    break;
                case '>':
                case '>=':
                case '<':
                case '<=':
                    if (!is_numeric($v)) {
                        return null;
                    }
                    $num      = (float)$v;
                    $ranges[] = match ($op) {
                        '>'  => [$num + self::EPSILON, PHP_FLOAT_MAX],
                        '>=' => [$num, PHP_FLOAT_MAX],
                        '<'  => [PHP_FLOAT_MIN, $num - self::EPSILON],
                        default => [PHP_FLOAT_MIN, $num],
                    };
                    break;
                default:
                    return null;
            }
        }

        // 有字面量候选：逐个检查是否还能通过「排除项」与「区间」
        if ($allowed !== null) {
            foreach ($allowed as $v) {
                if (in_array($v, $excluded, true)) {
                    continue;
                }
                $ok = true;
                foreach ($ranges as [$lo, $hi]) {
                    if (!is_numeric($v) || (float)$v < $lo || (float)$v > $hi) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return null;
                }
            }

            return '取值的交集为空（或全部被排除）';
        }

        // 只有区间：求交集，交集为空则必然取不到
        if ($ranges !== []) {
            $lo = max(array_column($ranges, 0));
            $hi = min(array_column($ranges, 1));
            if ($lo > $hi) {
                return '区间无交集';
            }
            if ($lo === $hi && in_array((string)$lo, $excluded, true)) {
                return '唯一可能取值被排除';
            }
        }

        return null;
    }

    /**
     * 规则的「条件」文本，用于提示信息。
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,string>
     */
    private static function condTexts(array $rules): array
    {
        return array_map(
            static fn ($r) => (string)($r['name'] ?? '') . '：' . (string)($r['field'] ?? '')
                . ' ' . (string)($r['operator'] ?? '') . ' ' . (string)($r['value'] ?? ''),
            $rules
        );
    }

    /** 恒不生效的取值（保存时允许，但永远不会命中任何行） */
    private static function neverHitReason(array $rule): ?string
    {
        if ((string)($rule['action'] ?? '') !== 'row') {
            return null;
        }
        if (((string)($rule['value_type'] ?? 'static')) !== 'static') {
            return null;
        }

        $op = strtolower(trim((string)($rule['operator'] ?? '')));
        $v  = (string)($rule['value'] ?? '');

        if ($op === 'in' && self::splitList($v) === []) {
            return '「属于」的取值为空，会生成 `IN (\'\')`：这条规则恒不生效（命中的用户看不到任何数据）';
        }
        if ($op === 'between' && count(array_map('trim', explode(',', $v))) < 2) {
            return '「介于」需要两个取值，当前取值不完整：这条规则会被静默跳过';
        }

        return null;
    }

    /**
     * 逗号分隔取值 → 非空项数组（与 `DataScope::applyRowRule()` 的拆分口径一致）。
     *
     * @return array<int,string>
     */
    private static function splitList(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn ($item): bool => $item !== ''
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $rules 涉及的规则（第一条作为代表，用于取字段名）
     * @return array<string,mixed>
     */
    private static function finding(string $type, array $rules, string $table, string $message): array
    {
        $rules = array_values($rules);
        $names = [];
        $ids   = [];
        foreach ($rules as $rule) {
            $id           = (int)($rule['id'] ?? 0);
            $ids[]        = $id;
            $names[$id]   = (string)($rule['name'] ?? '');
        }

        return [
            'type'    => $type,
            'field'   => (string)($rules[0]['field'] ?? ''),
            'table'   => $table,
            'rules'   => array_values(array_unique($ids)),
            'names'   => $names,
            'message' => $message,
        ];
    }

    /**
     * 去重：同一个组合（类型 + 字段 + 涉及规则集合）只留一条。
     *
     * @param  array<int,array<string,mixed>> $findings
     * @return array<int,array<string,mixed>>
     */
    private static function dedupe(array $findings): array
    {
        $out  = [];
        $seen = [];
        foreach ($findings as $finding) {
            $ids = $finding['rules'];
            sort($ids);
            $key = $finding['type'] . '|' . $finding['field'] . '|' . implode(',', $ids);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $finding;
        }

        return $out;
    }
}
