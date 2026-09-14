<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\DataScope;
use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/**
 * 数据权限规则逻辑（sys_data_rule）。
 *
 * 两类规则：
 *   - 行级  `action = row` → 用 field / operator / value 往查询里注入 where；
 *   - 字段级 `action = hidden / readonly / mask / encrypt` → hidden / mask / encrypt 作用于出参，
 *     readonly 作用于入参（强制剔除，防越权提交）。
 *
 * `table_name` 指定规则作用在哪张表（不含前缀），空 = 不限表（对所有接入模块生效）。
 * 指定了表的规则只在该表上生效，避免「字段名在别的表里不存在」把 SQL 拼错。
 *
 * 绑定维度：用户 / 岗位 / 部门（含下级）/ 角色，**任一命中即生效**；四个都空 = 全局规则。
 *
 * 与 data_scope 的关系见 DataScope 的类注释：预设档决定基线，自定义行级规则在其上叠加。
 */
final class DataRuleLogic
{
    /** 可写入的列；dept_ids 是 JSON 列，单独归一化 */
    private const FIELDS = [
        'name', 'user_id', 'post_id', 'dept_ids', 'role_id',
        'table_name', 'field', 'action', 'operator', 'value', 'value_type', 'sort', 'remark',
    ];

    /** 界面需要的动作与操作符候选（与后端校验同一份来源） */
    public static function allowedActions(): array
    {
        return array_merge(['row'], DataScope::FIELD_ACTIONS);
    }

    /** 行级取值的动态变量候选（[{value,label}]，界面直接用） */
    public static function valueVars(): array
    {
        $out = [];
        foreach (DataScope::VALUE_VARS as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    public static function paginate(array $params): array
    {
        $query = SoftDelete::listQuery('data_rule', $params);
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        if (!empty($params['field'])) {
            $query->where('field', 'like', '%' . $params['field'] . '%');
        }
        if (!empty($params['table_name'])) {
            $query->where('table_name', $params['table_name']);
        }
        if (!empty($params['action'])) {
            $query->where('action', $params['action']);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return ['total' => $total, 'list' => self::decorate($list)];
    }

    public static function create(array $data): int
    {
        $data = self::prepare($data);

        if (($data['field'] ?? '') === '') {
            throw new ApiException('请选择字段', 422);
        }
        self::assertFieldInTable((string)($data['table_name'] ?? ''), (string)$data['field']);

        $data['action']      = (string)($data['action'] ?? 'row');
        $data['operator']    = (string)($data['operator'] ?? '=');
        $data['value']       = (string)($data['value'] ?? '');
        $data['value_type']  = (string)($data['value_type'] ?? 'static');
        $data['sort']        = (int)($data['sort'] ?? 0);
        $data['create_time'] = date('Y-m-d H:i:s');

        return (int)Db::name('data_rule')->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        $current = self::assertExists($id);

        $data = self::prepare($data);
        if (array_key_exists('field', $data) && $data['field'] === '') {
            throw new ApiException('请选择字段', 422);
        }

        // 只提交了表或字段其中之一时，用库里的另一项一起校验
        self::assertFieldInTable(
            (string)($data['table_name'] ?? $current['table_name']),
            (string)($data['field'] ?? $current['field'])
        );

        if (!$data) {
            return;
        }

        $data['update_time'] = date('Y-m-d H:i:s');
        Db::name('data_rule')->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertExists($id);
        Db::name('data_rule')->where('id', $id)->delete();
    }

    /**
     * 表单候选数据。
     *
     * 一次拿全，避免规则页因为跨模块调用（用户/岗位/角色/部门）而依赖别的模块权限。
     */
    public static function options(): array
    {
        return [
            // 用户列表默认不返回（可能成千上万条）：改由 searchUsers() 按关键词懒加载
            'users'           => [],
            'posts'           => SoftDelete::apply(Db::name('post'))->field('id,name')->order('sort', 'asc')->order('id', 'asc')->select()->toArray(),
            'roles'           => RoleLogic::tree(),
            'depts'           => DeptLogic::tree(),
            'tables'          => self::tables(),
            'actions'         => self::allowedActions(),
            'operators'       => DataScope::ROW_OPERATORS,
            'operator_labels' => DataScope::OPERATOR_LABELS,
            'value_types'     => DataScope::VALUE_TYPES,
            'value_vars'      => self::valueVars(),
        ];
    }

    /**
     * 绑定对象-用户候选（模糊搜索，默认无数据）。
     *
     * 两个用途：
     *   - 传 keyword：按 账号 / 昵称 模糊匹配，最多 20 条；
     *   - 传 ids：按 id 精确取回（编辑回显已绑定的用户，不受关键词影响）。
     *
     * @param array<int|string> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function searchUsers(string $keyword, array $ids = []): array
    {
        $field   = 'id,username,nickname,status';
        $ids     = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn ($id) => $id > 0
        )));

        if ($ids !== []) {
            return SoftDelete::apply(Db::name('user'))->whereIn('id', $ids)->field($field)->limit(50)->select()->toArray();
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        return SoftDelete::apply(Db::name('user'))
            ->field($field)
            ->where(static function ($q) use ($like): void {
                $q->where('username', 'like', $like)->whereOr('nickname', 'like', $like);
            })
            ->order('id', 'asc')
            ->limit(20)
            ->select()
            ->toArray();
    }

    /**
     * 受控表 + 字段候选（语义化名称直接取库表注释，不用手工维护映射）。
     *
     * 刻意不做进程内缓存：webman 的 worker 常驻，静态属性会跨请求存活，
     * 一旦缓存住，后台刚加的「受控表」就不会立刻出现在下拉里。
     * 这里每次实时读 information_schema + sys_data_scope_table（仅管理端低频调用）。
     *
     * @return array<int,array{table:string,full:string,label:string,fields:array<int,array{field:string,label:string,type:string}>}>
     */
    public static function tables(): array
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        $rows   = Db::query(
            'SELECT c.TABLE_NAME, t.TABLE_COMMENT, c.COLUMN_NAME, c.COLUMN_COMMENT, c.DATA_TYPE
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t
                 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE ?
              ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION',
            [$prefix . '%']
        );

        $guarded = DataScope::guardedTables();

        $out = [];
        foreach ($rows as $row) {
            $full = (string)$row['TABLE_NAME'];
            $bare = substr($full, strlen($prefix));
            // 只暴露「受控表」：其余表即使建了规则也不会生效，不放进候选
            if ($bare === '' || !in_array($bare, $guarded, true)) {
                continue;
            }

            $tableComment = trim((string)$row['TABLE_COMMENT']);
            $columnComment = trim((string)$row['COLUMN_COMMENT']);

            $out[$bare] ??= [
                'table'  => $bare,
                'full'   => $full,
                'label'  => $tableComment !== '' ? $tableComment : $bare,
                'fields' => [],
            ];
            $out[$bare]['fields'][] = [
                'field' => (string)$row['COLUMN_NAME'],
                'label' => $columnComment !== '' ? $columnComment : (string)$row['COLUMN_NAME'],
                'type'  => (string)$row['DATA_TYPE'],
            ];
        }

        return array_values($out);
    }

    /**
     * 校验 + 归一化（只处理提交里出现的键，因此新增和局部更新可以共用）。
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function prepare(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        if (array_key_exists('action', $data)) {
            $action = (string)$data['action'];
            if (!in_array($action, self::allowedActions(), true)) {
                throw new ApiException('未知的规则动作：' . $action, 422);
            }
            $data['action'] = $action;

            // 非行级规则不涉及操作符与取值，一并清空，避免列表里出现无意义的残留
            if ($action !== 'row') {
                $data['operator']   = '=';
                $data['value']      = '';
                $data['value_type'] = 'static';
            }
        }

        if (array_key_exists('table_name', $data)) {
            $table = trim((string)$data['table_name']);
            if ($table !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
                throw new ApiException('目标表名不合法', 422);
            }
            if ($table !== '' && !DataScope::canGuard($table)) {
                throw new ApiException("目标表 {$table} 不在受控表内，请先在「受控表」里登记", 422);
            }
            $data['table_name'] = $table;
        }

        if (array_key_exists('field', $data)) {
            // 字段名会被拼进 where 的列位置，只允许标识符
            $field = (string)$data['field'];
            if ($field !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                throw new ApiException('字段名只能是字母、数字、下划线，且不能以数字开头', 422);
            }
            $data['field'] = $field;
        }

        if (array_key_exists('operator', $data)) {
            $operator = strtolower(trim((string)$data['operator']));
            if (!in_array($operator, DataScope::ROW_OPERATORS, true)) {
                throw new ApiException('未知的操作符：' . $operator, 422);
            }
            $data['operator'] = $operator;
        }

        if (array_key_exists('value_type', $data)) {
            $valueType = (string)$data['value_type'];
            if (!in_array($valueType, DataScope::VALUE_TYPES, true)) {
                throw new ApiException('未知的取值类型：' . $valueType, 422);
            }
            $data['value_type'] = $valueType;
        }

        if (array_key_exists('dept_ids', $data)) {
            $deptIds = $data['dept_ids'];
            if (is_string($deptIds)) {
                $decoded = json_decode($deptIds, true);
                $deptIds = is_array($decoded) ? $decoded : [];
            }
            $deptIds = array_values(array_unique(array_filter(
                array_map('intval', (array)$deptIds),
                static fn ($id) => $id > 0
            )));
            $data['dept_ids'] = $deptIds ? json_encode($deptIds) : null;
        }

        foreach (['user_id', 'post_id', 'role_id', 'sort'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = max(0, (int)$data[$key]);
            }
        }

        return $data;
    }

    /**
     * 字段必须真实存在于目标表。
     *
     * 这是最容易被忽略的一类错配：规则写 `status` 却指定了没有该列的表，
     * 真正生效时才会在 SQL 上炸；这里在保存阶段就拦掉。
     */
    private static function assertFieldInTable(string $table, string $field): void
    {
        if ($table === '' || $field === '') {
            return;
        }

        $map = array_column(self::tables(), null, 'table');

        if (!isset($map[$table])) {
            throw new ApiException("目标表 {$table} 不在受控表内", 422);
        }

        foreach ($map[$table]['fields'] as $item) {
            if ($item['field'] === $field) {
                return;
            }
        }

        throw new ApiException("字段 {$field} 不存在于表 {$table}，请重新选择", 422);
    }

    /**
     * 把绑定 id 与目标表翻译成名称，列表就不必再查一遍。
     *
     * @param  array<int,array<string,mixed>> $list
     * @return array<int,array<string,mixed>>
     */
    private static function decorate(array $list): array
    {
        $users = SoftDelete::apply(Db::name('user'))->column('username', 'id');
        $posts = SoftDelete::apply(Db::name('post'))->column('name', 'id');
        $roles = SoftDelete::apply(Db::name('role'))->column('name', 'id');
        $depts = SoftDelete::apply(Db::name('dept'))->column('name', 'id');
        // 表注释只查一次，避免逐行调用 tables()
        $tables = array_column(self::tables(), null, 'table');

        foreach ($list as &$row) {
            $row['user_name'] = $users[(int)$row['user_id']] ?? '';
            $row['post_name'] = $posts[(int)$row['post_id']] ?? '';
            $row['role_name'] = $roles[(int)$row['role_id']] ?? '';

            $ids = json_decode((string)($row['dept_ids'] ?? 'null'), true);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            $row['dept_ids']   = $ids;
            $row['dept_names'] = implode('、', array_values(array_filter(
                array_map(static fn ($id) => $depts[$id] ?? '', $ids)
            )));

            $table = (string)($row['table_name'] ?? '');
            $row['table_label'] = $table === ''
                ? ''
                : (string)($tables[$table]['label'] ?? $table);
        }
        unset($row);

        return $list;
    }

    private static function assertExists(int $id): array
    {
        $row = SoftDelete::apply(Db::name('data_rule'))->where('id', $id)->find();
        if (!$row) {
            throw new ApiException('规则不存在', 404);
        }

        return $row;
    }
}
