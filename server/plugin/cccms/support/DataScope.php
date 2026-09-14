<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 数据权限：行级（where 注入）+ 字段级（hidden/readonly/mask/encrypt）。
 *
 * 绑定维度：用户 / 岗位 / 部门 / 角色，**任一命中即生效（OR）**；
 * 四个绑定都为空 = 全局规则，对所有非超管生效。绑定越具体，命中的人越少。
 *
 * 语义约定（已接通：用户管理）：
 *   - 1 全部数据：不加任何行级条件，**自定义行级规则也不生效**（「全部」就是不受约束）
 *   - 2 本部门及以下 / 3 本部门 / 4 仅本人：先加预设基线条件，**再叠加**自定义行级规则（AND）
 *   - 5 自定义规则：不加预设基线，只用自定义行级规则（没有规则 = 等同全部）
 *   - 多角色取最宽松（min）；无角色退化为「仅本人」
 *   - 字段级规则与 data_scope 无关，只要绑定匹配就生效（超管除外）
 *
 * 各业务表的维度差异用 $options 适配：系统表没有 create_by / dept_id，
 * 需要由调用方说明「仅本人」看哪一列、「本部门」如何落到本表。
 *
 * 字段名白名单由调用方保证（各 Logic 层只对可信字段名调用），
 * 本类不做拼接字符串，全部走参数化 where。
 */
final class DataScope
{
    public const FIELD_ACTIONS = ['hidden', 'readonly', 'mask', 'encrypt'];

    /** 行级操作符白名单（供规则管理界面校验，避免 operator 被注入） */
    public const ROW_OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'in', 'between'];

    /** 操作符的语义化名称（界面展示用，与 ROW_OPERATORS 一一对应） */
    public const OPERATOR_LABELS = [
        '='       => '等于',
        '!='      => '不等于',
        '<>'      => '不等于',
        '>'       => '大于',
        '>='      => '大于等于',
        '<'       => '小于',
        '<='      => '小于等于',
        'like'    => '包含',
        'in'      => '属于',
        'between' => '介于',
    ];

    /**
     * 行级取值的类型。
     *   - static ：取值就是字面量（可逗号分隔多个）；
     *   - dynamic：取值里的 `{变量}` 在执行时按当前用户解析（见 VALUE_VARS）。
     */
    public const VALUE_TYPES = ['static', 'dynamic'];

    /**
     * 动态变量：占位符 => 说明。
     *
     * 用法：行级规则把取值类型选为「动态变量」，取值里写占位符即可，例如
     *   `dept_id in {dept.subtree}`  → 本部门及其所有下级
     * 多个变量 / 字面量可混用（逗号分隔），会合并成一个集合走 `IN`。
     */
    public const VALUE_VARS = [
        '{user.id}'      => '当前用户 ID',
        '{dept.ids}'     => '我所属的部门（不含下级）',
        '{dept.subtree}' => '我所属部门及其所有下级',
        '{post.ids}'     => '我的岗位',
        '{role.ids}'     => '我的角色',
    ];

    /**
     * 受控表兜底名单（不含前缀）。
     *
     * 「某张表能否做数据权限」本质上取决于业务 Logic 有没有调用本类，
     * 正式名单由 `sys_data_scope_table` 可视化维护；这里只在该表不存在 /
     * 没有任何启用行时兜底，避免升级过程中退化成「全部表都可配」。
     * 只有受控表才会出现在规则页的「目标表」候选中，保存与执行时也才会放行。
     */
    public const DEFAULT_TABLES = ['user'];

    /** 当前受控表（不含前缀，仅启用中的）；表不存在时回退 DEFAULT_TABLES */
    public static function guardedTables(): array
    {
        try {
            $tables = Db::name('data_scope_table')->where('status', 1)->column('table_name');
        } catch (Throwable) {
            return self::DEFAULT_TABLES;
        }

        $tables = array_values(array_filter(
            array_map('strval', (array)$tables),
            static fn (string $t): bool => $t !== ''
        ));

        return $tables ?: self::DEFAULT_TABLES;
    }

    /** 该表是否已接入数据权限（受控表） */
    public static function canGuard(string $table): bool
    {
        return in_array($table, self::guardedTables(), true);
    }

    /**
     * 行级：向查询注入 where。
     *
     * @param array{
     *     owner?: string,
     *     table?: string,
     *     dept?: callable(mixed, array<int>): void
     * } $options
     *   - owner：「仅本人」按哪个列判定，默认 `create_by`。
     *     系统表（如 sys_user）没有 create_by，应传 `'id'`（"仅本人"= 只看自己这个账号）。
     *   - table：当前查询的表（不含前缀），用于只取「不限表」或「指定了这张表」的规则。
     *   - dept：「本部门 / 及以下」如何落到当前表，回调收到的是**已展开好的**部门 id 列表。
     *     不传则默认 `whereIn('dept_id', $ids)`（适用于带 dept_id 的业务表）。
     *     回调需自行 fail-closed：列表为空时不能放行任何数据。
     */
    public static function row($query, UserContext $user, array $options = []): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $scope = self::roleDataScope($user);
        if ($scope === 1) {
            return;
        }

        if ($scope === 4) {
            $query->where($options['owner'] ?? 'create_by', $user->id);
        } elseif (in_array($scope, [2, 3], true)) {
            $ids = self::userDeptIds($user);
            if ($scope === 2) {
                $ids = self::deptAndChildren($ids);
            }

            $applyDept = $options['dept'] ?? null;
            if (is_callable($applyDept)) {
                $applyDept($query, $ids);
            } elseif ($ids) {
                $query->whereIn('dept_id', $ids);
            } else {
                // fail-closed：声明了「本部门」却没有任何部门，不能退化成「不过滤」
                $query->whereIn('dept_id', [0]);
            }
        }

        // 自定义行级规则（action=row），在预设基线之上叠加
        $rules = self::rules($user, 'row', (string)($options['table'] ?? ''));

        // 自定义档没有预设基线，完全依赖规则：一条都没命中时必须 fail-closed。
        // 否则「绑错对象 / 忘了配规则」会静默变成「看全部」，比不配还危险。
        if ($scope === 5 && $rules === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($rules as $rule) {
            self::applyRowRule($query, $rule, $user);
        }
    }

    /**
     * 应用一条行级规则。
     *
     * in / between 的取值在界面里用逗号分隔，这里拆成数组走 whereIn / whereBetween；
     * 否则 where(field, 'in', 'a,b') 会生成非法 SQL。
     *
     * value_type=dynamic 时，取值里的 `{变量}` 会先按当前用户解析成 id 集合
     * （可与字面量混用），因此 `dept_id in {dept.subtree}` 这类「动态范围」
     * 也能作为普通自定义规则叠加在 data_scope 基线上。
     *
     * @param array<string,mixed> $rule
     */
    private static function applyRowRule($query, array $rule, UserContext $user): void
    {
        $field     = (string)$rule['field'];
        $operator  = strtolower(trim((string)$rule['operator']));
        $raw       = (string)$rule['value'];
        $isDynamic = ((string)($rule['value_type'] ?? 'static')) === 'dynamic';

        // 静态取值保持原语义（含 `= ''` 这种边界），只有动态取值才需要解析变量
        $values = $isDynamic ? self::resolveValues($raw, $user) : [];

        if ($operator === 'in') {
            $items = $isDynamic
                ? $values
                : array_values(array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn ($item) => $item !== ''
                ));
            // fail-closed：解析后为空不能退化成「不过滤」
            $query->whereIn($field, $items ?: ['']);

            return;
        }

        if ($operator === 'between') {
            $parts = $isDynamic
                ? $values
                : array_map('trim', explode(',', $raw));
            if (count($parts) < 2) {
                return; // 取值不完整：宁可这条规则不生效，也不生成非法 SQL
            }
            $query->whereBetween($field, [$parts[0], $parts[1]]);

            return;
        }

        if (!in_array($operator, self::ROW_OPERATORS, true)) {
            return;
        }

        if (!$isDynamic) {
            $query->where($field, $operator, $raw);

            return;
        }

        // 动态变量没解析出任何值（如用户没有部门）→ 该条规则不生效
        if ($values === []) {
            return;
        }
        // 变量展开成多个值（{dept.subtree} 等）时，标量操作符按「属于」处理
        if (count($values) > 1) {
            $query->whereIn($field, $values);

            return;
        }

        $query->where($field, $operator, $values[0]);
    }

    /**
     * 解析动态取值：按逗号拆分，把 `{变量}` 展开为当前用户对应的 id 集合。
     *
     * 只有「整段就是一个占位符」才会被展开（`1,{dept.ids}` 这种混用也支持）。
     *
     * @return array<int,string>
     */
    private static function resolveValues(string $raw, UserContext $user): array
    {
        $out = [];
        foreach (array_map('trim', explode(',', $raw)) as $part) {
            if ($part === '') {
                continue;
            }
            if (isset(self::VALUE_VARS[$part])) {
                foreach (self::variableValues($part, $user) as $value) {
                    $out[] = (string)$value;
                }
                continue;
            }
            $out[] = $part;
        }

        return array_values(array_unique($out));
    }

    /**
     * 单个变量 → 当前用户对应的值集合。
     *
     * @return array<int,int|string>
     */
    private static function variableValues(string $var, UserContext $user): array
    {
        return match ($var) {
            '{user.id}'      => [$user->id],
            '{dept.ids}'     => self::userDeptIds($user),
            '{dept.subtree}' => self::deptAndChildren(self::userDeptIds($user)),
            '{post.ids}'     => self::userPostIds($user),
            '{role.ids}'     => self::userRoleIds($user),
            default          => [],
        };
    }

    /**
     * 字段级：过滤结果集。
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string                         $table 当前查询的表（不含前缀）；用于筛掉指定了别的表的规则
     */
    public static function field(array &$rows, UserContext $user, string $table = ''): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        $rules = self::rules($user, 'field', $table);
        if (!$rules) {
            return;
        }
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($rules as $rule) {
                $f = $rule['field'];
                if (!array_key_exists($f, $row)) {
                    continue;
                }
                switch ($rule['action']) {
                    case 'hidden':
                        unset($row[$f]);
                        break;
                    case 'mask':
                        $row[$f] = self::mask((string)$row[$f], $f);
                        break;
                    case 'encrypt':
                        $row[$f] = Cipher::encrypt((string)$row[$f]);
                        break;
                    // readonly：仅入参剔除（见 Logic 层），出参不处理
                }
            }
        }
        unset($row);
    }

    /**
     * 入参剔除：readonly 字段强制移除，防止前端提交越权修改。
     *
     * @param array<string,mixed> $data
     * @param string              $table 当前写入的表（不含前缀）
     */
    public static function stripReadonly(array &$data, UserContext $user, string $table = ''): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        foreach (self::rules($user, 'field', $table) as $rule) {
            if ($rule['action'] === 'readonly') {
                unset($data[$rule['field']]);
            }
        }
    }

    /**
     * 获取用户的角色 data_scope（取并集，即最宽松值最小者）。
     * 无角色 → 退化为「仅本人」。
     */
    private static function roleDataScope(UserContext $user): int
    {
        $roleIds = self::userRoleIds($user);
        if (!$roleIds) {
            return 4;
        }
        $scopes = SoftDelete::apply(Db::name('role'))->where('id', 'in', $roleIds)->where('status', 1)->column('data_scope');
        if (!$scopes) {
            return 4;
        }
        return (int)min(array_map('intval', $scopes));
    }

    /**
     * 读取适用的数据权限规则。
     *
     * @param string|null $action 'row' 或 'field'（null=全部）
     * @param string      $table  当前查询的表（不含前缀）
     * @return array<int,array<string,mixed>>
     */
    private static function rules(UserContext $user, ?string $action, string $table = ''): array
    {
        // 已进回收站的规则不能再生效
        $q = SoftDelete::apply(Db::name('data_rule'));
        if ($action === 'row') {
            $q->where('action', 'row');
        } elseif ($action === 'field') {
            $q->where('action', '<>', 'row');
        }
        $all = $q->select()->toArray();

        // 目标表过滤：规则没指定表 = 对所有模块生效；指定了表的，只在该表上生效。
        // 调用方没声明自己的表时，指定了表的规则一律不用（避免把别的表的列拼进当前 SQL）。
        // 未接入数据权限的表即使被写了规则也不会生效（与「受控表」保持一致）。
        $guarded = self::guardedTables();
        $all     = array_values(array_filter(
            $all,
            static function ($r) use ($table, $guarded) {
                $bound = (string)($r['table_name'] ?? '');
                if ($bound === '') {
                    return true;
                }
                return $table !== '' && $bound === $table && in_array($bound, $guarded, true);
            }
        ));

        $postIds = self::userPostIds($user);
        $roleIds = self::userRoleIds($user);
        $deptIds = self::userDeptIds($user);
        // 部门绑定按「含下级」处理：绑「总公司」要覆盖「研发部」的人。
        // 部门树只取一次，避免每条规则各查一遍。
        $deptParents = SoftDelete::apply(Db::name('dept'))->column('parent_id', 'id');

        return array_values(array_filter(
            $all,
            function ($r) use ($user, $postIds, $roleIds, $deptIds, $deptParents) {
                $dept = json_decode((string)($r['dept_ids'] ?? 'null'), true);
                $dept = is_array($dept) ? array_map('intval', $dept) : [];

                $hasBinding = (int)$r['user_id'] > 0 || (int)$r['post_id'] > 0 || (int)$r['role_id'] > 0 || !empty($dept);
                if (!$hasBinding) {
                    return true; // 无绑定 = 全局规则
                }
                if ((int)$r['user_id'] === $user->id) {
                    return true;
                }
                if ((int)$r['post_id'] > 0 && in_array((int)$r['post_id'], $postIds, true)) {
                    return true;
                }
                if ((int)$r['role_id'] > 0 && in_array((int)$r['role_id'], $roleIds, true)) {
                    return true;
                }
                if (empty($dept)) {
                    return false;
                }

                // 绑定部门展开成子树后再与「用户所属部门」求交集
                return count(array_intersect(self::deptAndChildren($dept, $deptParents), $deptIds)) > 0;
            }
        ));
    }

    private static function userRoleIds(UserContext $user): array
    {
        return array_map('intval', Db::name('user_role')->where('user_id', $user->id)->column('role_id'));
    }

    private static function userPostIds(UserContext $user): array
    {
        return array_map('intval', Db::name('user_post')->where('user_id', $user->id)->column('post_id'));
    }

    private static function userDeptIds(UserContext $user): array
    {
        return array_map('intval', Db::name('user_dept')->where('user_id', $user->id)->column('dept_id'));
    }

    /**
     * 部门子树展开：给定部门 + 其所有下级部门。
     *
     * @param array<int,int>             $ids
     * @param array<int|string,int>|null $parents id => parent_id；批量调用时可传入复用，省一次查询
     */
    private static function deptAndChildren(array $ids, ?array $parents = null): array
    {
        $result  = array_map('intval', $ids);
        $parents ??= SoftDelete::apply(Db::name('dept'))->column('parent_id', 'id');
        $queue = $result;
        while ($queue) {
            $parent = (int)array_shift($queue);
            foreach ($parents as $id => $pid) {
                $id = (int)$id;
                if ((int)$pid === $parent && !in_array($id, $result, true)) {
                    $result[] = $id;
                    $queue[] = $id;
                }
            }
        }
        return $result;
    }

    private static function mask(string $value, string $field): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($field, 'phone') || str_contains($field, 'mobile')) {
            return substr($value, 0, 3) . '****' . substr($value, -4);
        }
        if (str_contains($field, 'id_card') || str_contains($field, 'idcard')) {
            return substr($value, 0, 4) . '**********' . substr($value, -4);
        }
        $len = strlen($value);
        if ($len <= 2) {
            return $value;
        }
        $show = (int)floor($len / 3);
        return substr($value, 0, $show) . str_repeat('*', $len - 2 * $show) . substr($value, -$show);
    }
}
