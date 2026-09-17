<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use plugin\cccms\support\DataScope;
use think\Model;
use think\model\concern\SoftDelete;

/**
 * 模型基类。表名统一省略 sys_ 前缀（连接配置 prefix 自动补全）。
 *
 * 数据权限：
 *   所有模型**默认接入**（`$globalScope`），查询会自动带上当前用户的数据范围，
 *   业务层不需要记得调用 `DataScope::row()` —— 单条读写入口（详情 / 编辑 / 删除）
 *   同样被覆盖，不会出现「列表过滤了、按 id 直调却越权」的漏网。
 *
 *   参与方式由模型自己声明 `$dataScope`：
 *     - `false`：不参与（基础设施表，如 sys_menu / sys_config / 字典 / 关联表）；
 *     - 数组：参与，可覆盖 `owner`（「仅本人」按哪列）/ `dept`（「本部门」如何落到本表）
 *       / `no_baseline`（表里没有 owner 与部门列，隔离完全由自定义规则表达）；
 *     - 不声明（默认 `[]`）：按 `create_by` / `dept_id` 走基线。
 *
 *   不声明又确实没有这两列的表，查询会直接报「列不存在」—— 这是**有意的**：
 *   逼你把「这张表的数据范围怎么算」写清楚，而不是静默放行。
 *
 *   生效条件（缺一不可）：
 *     1. 模型参与（`$dataScope !== false`）；
 *     2. 有当前用户（CLI / 定时任务 / 登录链路 / 公共路由没有用户，自动跳过）；
 *     3. 非超管、且角色不是「全部数据」。
 *   预设基线对所有参与的模型生效；**自定义规则**只对登记在 `sys_data_scope_table`
 *   的受控表生效（登记入口见数据权限规则页的「受控表」）。
 *
 *   逃生口：`User::withoutGlobalScope()->where(...)`（think-orm 自带），
 *   用于登录链路、CLI、以及「必须读到全量数据」的场景（如唯一性校验）。
 */
abstract class BaseModel extends Model
{
    /**
     * 软删除（think-orm 自带轨迹）。
     *
     * 只要模型对应表有 `delete_time`（默认名），查询就会**自动排除已删数据**，
     * 业务层不需要再记得调用 `SoftDelete::apply()`；回收站视图用 `Model::onlyTrashed()`，
     * 需要连已删数据一起操作（如恢复、彻底删除）用 `Model::withTrashed()`。
     *
     * 表里没有 `delete_time` 的模型必须显式声明 `protected $deleteTime = false;`，
     * 否则会拼出不存在的列 —— 这类表已在各自模型里声明。
     */
    use SoftDelete;

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    /** 全局查询作用域：数据权限（处理方法见 scopeDataScope） */
    protected $globalScope = ['dataScope'];

    /** 自定义查询类：写入时剔除当前用户不可写的字段（见 ScopedQuery） */
    protected $query = ScopedQuery::class;

    /**
     * 数据权限声明。
     *
     * false                         → 不参与（基础设施表）
     * ['owner' => 'id']             → 参与，覆盖「仅本人」判定列
     * ['dept'  => [self::class, 'deptScope']] → 参与，自定义「本部门」落地方式
     * ['no_baseline' => true]       → 参与，但不加预设基线（仅自定义规则生效）
     *
     * @var array<string,mixed>|bool
     */
    protected $dataScope = [];

    /**
     * 全局作用域回调：think-orm 构造查询时按 `$globalScope` 里的名字挂载。
     *
     * 方法名必须是 `scope` + 声明名（`dataScope` → `scopeDataScope`），且为 public
     * （框架用 call_user_func_array 调用）。参数固定为当前查询对象。
     *
     * @param mixed $query 模型查询对象
     */
    public function scopeDataScope($query): void
    {
        DataScope::applyToModelQuery($query, $this->getName(), $this->dataScope);
    }

    /**
     * 出参统一过滤：字段级数据权限规则（`hidden` / `mask` / `encrypt`）。
     *
     * 放在 `toArray()` 里，列表、详情、导出等所有出参路径自动生效，业务层不需要
     * 记得调用 `DataScope::field()`；写入侧由 `ScopedQuery` 负责剔除不可写字段，
     * 所以「掩码值被表单原样提交回库」不会发生。
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        DataScope::applyFieldRules($data, $this->getName());

        return $data;
    }

    /**
     * 起始一个**带数据权限作用域**的查询对象。
     *
     * 静态魔术调用（`User::where(...)`）同样会带上作用域，正常业务直接用它；
     * 这里用于「先拿到 Query 再交给工具处理」的场景。
     */
    public static function newScopedQuery(): \think\db\BaseQuery
    {
        return (new static())->db();
    }
}
