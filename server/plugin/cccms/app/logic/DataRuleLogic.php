<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\DataRule;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\Csv;
use plugin\cccms\support\RuleConflict;
use plugin\cccms\support\DataScope;
use think\facade\Db;
use Throwable;
use Webman\Http\Response;
use Webman\Http\UploadFile;

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
 * 绑定维度：用户 / 岗位 / 部门（含下级）/ 角色，组合方式由 `bind_mode` 决定：
 *   - `or`（默认）：任一维度命中即生效（更宽）；
 *   - `and`：所有**已填写**的维度都要命中（更窄，用于「张三 且 在客服岗」这类表达）。
 * 四个维度都空 = 全局规则，与 `bind_mode` 无关。
 *
 * 与 data_scope 的关系见 DataScope 的类注释：预设档决定基线，自定义行级规则在其上叠加。
 */
final class DataRuleLogic
{
    /**
     * 可写入的列；dept_ids 是 JSON 列，单独归一化。
     *
     * 没有 `sort`：规则的先后顺序对生效结果没有影响（行级规则是 AND 叠加，
     * 字段级规则按字段各自处理），原先那个「排序」只影响列表行序、且列表里都不显示，
     * 属于会让人误以为有权重的假旋钮。列表按 id（即创建顺序）稳定输出。
     */
    private const FIELDS = [
        'name', 'user_id', 'post_id', 'dept_ids', 'role_id', 'bind_mode',
        'table_name', 'field', 'action', 'operator', 'value', 'value_type', 'remark',
    ];

    /** 导出上限：避免一次导出把内存打满 */
    private const EXPORT_LIMIT = 5000;

    /**
     * 导出 / 模板表头。
     *
     * 前 13 列与 `sys_data_rule` 字段一一对应（导入也只读这些列）；
     * 末尾 4 列是绑定对象名称，**仅供人工校对**，导入时忽略 —— 名称可能重名，一律以 id 为准。
     */
    private const EXPORT_HEADERS = [
        'name', 'user_id', 'post_id', 'dept_ids', 'role_id', 'bind_mode',
        'table_name', 'field', 'action', 'operator', 'value', 'value_type', 'remark',
        'user_name', 'post_name', 'dept_names', 'role_name',
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
        // trashed=1 → 回收站视图；规则表是数据权限自身的元数据，模型声明为不参与
        $query = !empty($params['trashed']) ? DataRule::onlyTrashed() : DataRule::newScopedQuery();
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
        $list  = $query->page($page, $limit)->order('id', 'asc')->select()->toArray();

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
        $data['bind_mode']   = (string)($data['bind_mode'] ?? 'or');
        $data['create_time'] = date('Y-m-d H:i:s');

        return (int)DataRule::withoutGlobalScope()->insertGetId($data);
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
        DataRule::where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertExists($id);
        DataRule::where('id', $id)->delete();
    }

    /** 导出当前筛选下的规则（CSV，直接返回文件流） */
    public static function export(array $params): Response
    {
        // 筛选条件与 paginate 保持一致，导出的就是「眼前这一屏」的范围
        $query = !empty($params['trashed']) ? DataRule::onlyTrashed() : DataRule::newScopedQuery();
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

        $rows = $query->order('id', 'asc')->limit(self::EXPORT_LIMIT)->select()->toArray();

        // 绑定 id → 名称映射一次预载，避免逐行查库（N+1）。
        // 必须看全量，否则范围外的绑定对象会导出成空名字（同 decorate() 的理由）。
        $userNames = User::withoutGlobalScope()->column('username', 'id');
        $postNames = Post::withoutGlobalScope()->column('name', 'id');
        $roleNames = Role::withoutGlobalScope()->column('name', 'id');
        $deptNames = Dept::withoutGlobalScope()->column('name', 'id');

        $data = array_map(static function (array $row) use ($userNames, $postNames, $roleNames, $deptNames): array {
            // dept_ids 是 JSON 列，模型已解码成数组；多值统一用 `|` 拼接（见 parseDeptIds 注释）
            $deptIds = is_array($row['dept_ids'] ?? null) ? array_map('intval', $row['dept_ids']) : [];

            return [
                (string)$row['name'],
                (string)$row['user_id'],
                (string)$row['post_id'],
                implode('|', $deptIds),
                (string)$row['role_id'],
                (string)$row['bind_mode'],
                (string)$row['table_name'],
                (string)$row['field'],
                (string)$row['action'],
                (string)$row['operator'],
                (string)$row['value'],
                (string)$row['value_type'],
                (string)($row['remark'] ?? ''),
                (string)($userNames[(int)$row['user_id']] ?? ''),
                (string)($postNames[(int)$row['post_id']] ?? ''),
                // 部门名与 dept_ids 同序，人工校对时能一一对应
                implode('|', array_values(array_filter(array_map(
                    static fn ($id): string => (string)($deptNames[(int)$id] ?? ''),
                    $deptIds
                )))),
                (string)($roleNames[(int)$row['role_id']] ?? ''),
            ];
        }, $rows);

        return Csv::download('数据权限规则', self::EXPORT_HEADERS, $data);
    }

    /** 下载导入模板 */
    public static function template(): Response
    {
        return Csv::download('数据权限规则导入模板', array_merge(self::EXPORT_HEADERS, ['说明']), [
            [
                '客服只看未关闭工单', '0', '0', '', '0', 'or', 'ticket', 'status', 'row', '=', '0', 'static',
                '示例：只对工单表生效，status 等于 0',
                '', '', '', '',
                'user_id / post_id / role_id 填绑定对象 ID（0 = 不绑定）；dept_ids 多个用 | 分隔；'
                . 'bind_mode 取 or/and；action 取 row/hidden/readonly/mask/encrypt；value_type 取 static/dynamic。'
                . '末尾 *_name 列仅供人工校对，导入时忽略；同名规则会被更新，否则新增。',
            ],
        ]);
    }

    /**
     * 导入数据权限规则（CSV）。
     *
     * 约定：
     *   - `name` 必填，作为天然业务键：同名规则**更新**，否则**新增**；
     *   - `dept_ids` 多值用 `|` 分隔（CSV 里逗号是分隔符，避免嵌套转义歧义）；
     *   - 末尾 `*_name` 辅助列仅供人工校对，导入时忽略，绑定一律以 id 为准；
     *   - 校验（受控表 / 字段存在性 / 动作与操作符枚举）全部复用 create / update，不另写一套；
     *   - 单行失败不影响其余行，失败原因按行号汇总返回。
     *
     * @return array{total:int,created:int,updated:int,failed:array<int,string>}
     */
    public static function import(UploadFile $file): array
    {
        $parsed  = Csv::parse($file);
        $headers = $parsed['headers'];

        if (!in_array('name', $headers, true)) {
            throw new ApiException('CSV 缺少 name 列，请先下载导入模板', 422);
        }

        // 绑定对象存在性校验必须看**全量**，显式跳出数据权限：导入是系统配置动作，
        // 否则「看不见的用户 / 岗位 / 角色 / 部门」会被误判为不存在（同 UserLogic::import()）。
        $userMap = array_flip(array_map('intval', User::withoutGlobalScope()->column('id')));
        $postMap = array_flip(array_map('intval', Post::withoutGlobalScope()->column('id')));
        $roleMap = array_flip(array_map('intval', Role::withoutGlobalScope()->column('id')));
        $deptMap = array_flip(array_map('intval', Dept::withoutGlobalScope()->column('id')));

        $created = 0;
        $updated = 0;
        $failed  = [];

        foreach ($parsed['rows'] as $index => $row) {
            $line = $index + 2;   // 第 1 行是表头
            $name = trim((string)($row['name'] ?? ''));

            if ($name === '') {
                $failed[] = "第 {$line} 行：规则名为空";
                continue;
            }

            try {
                $boundDeptIds = self::parseDeptIds((string)($row['dept_ids'] ?? ''));
                self::assertBindingsExist(
                    (int)($row['user_id'] ?? 0),
                    (int)($row['post_id'] ?? 0),
                    (int)($row['role_id'] ?? 0),
                    $boundDeptIds,
                    $userMap,
                    $postMap,
                    $roleMap,
                    $deptMap
                );

                $data = [
                    'name'       => $name,
                    'user_id'    => max(0, (int)($row['user_id'] ?? 0)),
                    'post_id'    => max(0, (int)($row['post_id'] ?? 0)),
                    'dept_ids'   => $boundDeptIds,
                    'role_id'    => max(0, (int)($row['role_id'] ?? 0)),
                    'bind_mode'  => trim((string)($row['bind_mode'] ?? '')) ?: 'or',
                    'table_name' => trim((string)($row['table_name'] ?? '')),
                    'field'      => trim((string)($row['field'] ?? '')),
                    'action'     => trim((string)($row['action'] ?? '')) ?: 'row',
                    'operator'   => trim((string)($row['operator'] ?? '')) ?: '=',
                    'value'      => (string)($row['value'] ?? ''),
                    'value_type' => trim((string)($row['value_type'] ?? '')) ?: 'static',
                    'remark'     => (string)($row['remark'] ?? ''),
                ];

                // 以规则名为天然键判断新增 / 更新（只看未删除的规则；回收站里的同名规则按新增处理）
                $exist = DataRule::withoutGlobalScope()->where('name', $name)->find();
                if ($exist) {
                    self::update((int)$exist['id'], $data);
                    $updated++;
                } else {
                    self::create($data);
                    $created++;
                }
            } catch (Throwable $e) {
                $failed[] = "第 {$line} 行：" . $e->getMessage();
            }
        }

        return [
            'total'   => count($parsed['rows']),
            'created' => $created,
            'updated' => $updated,
            'failed'  => $failed,
        ];
    }

    /**
     * 解析 `dept_ids` 多值列（`|` 分隔）。
     *
     * 用 `|` 而不是逗号：CSV 以逗号分列，逗号会破坏列对齐或需要嵌套转义。
     *
     * @return array<int,int>
     */
    private static function parseDeptIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (explode('|', $raw) as $token) {
            $id = (int)trim($token);
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 绑定 id 必须真实存在，不存在即整行失败。
     *
     * 刻意不静默丢弃：否则规则会「看起来配了绑定、实际谁都不命中」，比报错更难排查。
     *
     * @param array<int,int> $deptIds
     * @param array<int,int> $userMap 全量 id 集合（array_flip 后的 id => 下标）
     */
    private static function assertBindingsExist(
        int $userId,
        int $postId,
        int $roleId,
        array $deptIds,
        array $userMap,
        array $postMap,
        array $roleMap,
        array $deptMap
    ): void {
        if ($userId > 0 && !isset($userMap[$userId])) {
            throw new ApiException("绑定的用户 ID {$userId} 不存在", 422);
        }
        if ($postId > 0 && !isset($postMap[$postId])) {
            throw new ApiException("绑定的岗位 ID {$postId} 不存在", 422);
        }
        if ($roleId > 0 && !isset($roleMap[$roleId])) {
            throw new ApiException("绑定的角色 ID {$roleId} 不存在", 422);
        }
        foreach ($deptIds as $id) {
            if (!isset($deptMap[$id])) {
                throw new ApiException("绑定的部门 ID {$id} 不存在", 422);
            }
        }
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
            // 候选项一律取全量（岗位表声明不参与数据权限，这里显式写出意图）
            'posts'           => Post::withoutGlobalScope()->field('id,name')->order('sort', 'asc')->order('id', 'asc')->select()->toArray(),
            'roles'           => RoleLogic::tree(),
            // 绑定部门候选必须全量：规则是配置动作，不能因为「看不见某个部门」就绑不了
            'depts'           => DeptLogic::treeAll(),
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

        // 规则配置属于系统配置界面（不是业务数据浏览），用户选择器需要全量候选，
        // 否则「看不到的用户既选不了、已绑定的也显示成空名字」；因此显式跳出数据权限。
        if ($ids !== []) {
            return User::withoutGlobalScope()->whereIn('id', $ids)->field($field)->limit(50)->select()->toArray();
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        return User::withoutGlobalScope()
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

        if (array_key_exists('bind_mode', $data)) {
            $mode = strtolower(trim((string)$data['bind_mode']));
            if (!in_array($mode, DataScope::BIND_MODES, true)) {
                throw new ApiException('未知的绑定关系：' . $mode . '（只能是 or / and）', 422);
            }
            $data['bind_mode'] = $mode;
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

        foreach (['user_id', 'post_id', 'role_id'] as $key) {
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
        // 把绑定 id 翻译成名字用于展示：必须看全量，否则已绑定的用户会显示成空名字
        $users = User::withoutGlobalScope()->column('username', 'id');
        $posts = Post::withoutGlobalScope()->column('name', 'id');
        $roles = Role::withoutGlobalScope()->column('name', 'id');
        // 部门参与数据权限，必须显式跳出：否则范围外的部门会显示成空名字
        $depts = Dept::withoutGlobalScope()->column('name', 'id');
        // 表注释只查一次，避免逐行调用 tables()
        $tables = array_column(self::tables(), null, 'table');

        // 规则体检：条件互斥这类问题「配的时候看不出来、用的时候页面空白」，
        // 直接挂到列表上，比让人去记着跑命令行靠谱。需要全量规则才能比较，故单独查一次。
        $conflicts = RuleConflict::byRule(
            RuleConflict::check(DataRule::newScopedQuery()->select()->toArray())
        );

        foreach ($list as &$row) {
            $row['user_name'] = $users[(int)$row['user_id']] ?? '';
            $row['post_name'] = $posts[(int)$row['post_id']] ?? '';
            $row['role_name'] = $roles[(int)$row['role_id']] ?? '';

            // 绑定组合方式：与 DataScope::rules() 的判定保持一致（非法值按 or）
            $mode = strtolower(trim((string)$row['bind_mode']));
            $mode = in_array($mode, DataScope::BIND_MODES, true) ? $mode : 'or';
            $row['bind_mode']       = $mode;
            $row['bind_mode_label'] = DataScope::BIND_MODE_LABELS[$mode];

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

            $row['conflicts'] = array_values(array_map(
                static fn ($item): array => ['type' => $item['type'], 'message' => $item['message']],
                $conflicts[(int)$row['id']] ?? []
            ));
        }
        unset($row);

        return $list;
    }

    private static function assertExists(int $id): array
    {
        $row = DataRule::withoutGlobalScope()->where('id', $id)->find();
        if (!$row) {
            throw new ApiException('规则不存在', 404);
        }

        return $row->toArray();
    }
}
