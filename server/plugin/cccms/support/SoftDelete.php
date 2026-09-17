<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;

/**
 * 软删除（**查询构造器**版）。
 *
 * 走模型的业务查询**已经不需要它**：`BaseModel` 用了 think-orm 的 `SoftDelete` trait，
 * 模型查询自动排除已删数据，回收站视图用 `Model::onlyTrashed()`、含已删行用
 * `Model::withTrashed()`、软删/恢复用 `Model::destroy()` / `$model->restore()`。
 *
 * 这里保留的是**查询构造器直查**（`Db::name()` / `Db::table()`）场景所需的显式条件：
 *   - `RecycleLogic`：回收站要跨表操作「用户不可见的已删数据」，是天然的显式场景；
 *   - `support/` 基础设施：`AuthService`（登录校验）、`DataScope`（规则/部门树）、
 *     `process/Crontab`（调度进程）、`CategoryLogic` / `DictLogic` / `MenuLogic` /
 *     `DashboardLogic`（尚未模型化，属 §3.9 的后续项）。
 *
 *   SoftDelete::apply($q)           普通查询：排除已删除
 *   SoftDelete::onlyTrashed($q)     回收站：只看已删除
 *   SoftDelete::listQuery($t, $p)   列表入口：按请求参数 trashed 自动选上面两者
 *   SoftDelete::listQueryFull($t,$p) 同上，但表名不套连接前缀（业务插件表）
 *   SoftDelete::remove($q, $ids)    软删除（批量 UPDATE）
 *   SoftDelete::restore($q, $ids)   恢复
 *   SoftDelete::force($q, $ids)     彻底删除（真 DELETE）
 *
 * 约定：`delete_time IS NULL` = 未删除；非 NULL = 已删除。
 *
 * 注意：构造器直查是「显式」的——**每个读取入口都要记得调用 apply()**；
 * 能走模型的地方请优先走模型（自动过滤，不会漏）。
 */
final class SoftDelete
{
    public const FIELD = 'delete_time';

    /** 普通查询：排除已删除 */
    public static function apply($query)
    {
        return $query->useSoftDelete(self::FIELD, ['null', '']);
    }

    /**
     * 列表 / 树的数据源开关：`true` 取回收站（只看已删除），`false` 排除已删除。
     *
     * 前端各模块页面的「回收站」按钮切换的就是它：**同一张表、同一套列，只换数据源**，
     * 不必为回收站单独做一套接口和界面。
     */
    public static function scope($query, bool $trashed)
    {
        return $trashed ? self::onlyTrashed($query) : self::apply($query);
    }

    /**
     * 列表查询入口：按请求参数 `trashed` 决定数据源。
     *
     * @param array<string,mixed> $params 控制器透传的查询参数
     */
    public static function listQuery(string $table, array $params)
    {
        return self::scope(Db::name($table), !empty($params['trashed']));
    }

    /** 该行是否已进回收站（按已取出的行判断，不再查库） */
    public static function isTrashed(array $row): bool
    {
        return !empty($row[self::FIELD]);
    }

    /**
     * 同 listQuery，但 `$table` 为**完整表名**（不走连接前缀）。
     *
     * 用于业务插件表（如 `ks_*`，连接前缀 `sys_` 不适用）：
     * 业务 Logic 只需 `SoftDelete::listQueryFull('ks_subject', $params)`，
     * 无需自己写 `SoftDelete::scope(Db::table(...), ...)`。
     */
    public static function listQueryFull(string $table, array $params)
    {
        return self::scope(Db::table($table), !empty($params['trashed']));
    }

    /** 回收站：只看已删除 */
    public static function onlyTrashed($query)
    {
        return $query->useSoftDelete(self::FIELD, ['notnull', '']);
    }

    /** 软删除（批量传 id 数组） */
    public static function remove($query, int|array $ids): int
    {
        return $query
            ->whereIn('id', array_map('intval', (array)$ids))
            ->useSoftDelete(self::FIELD, date('Y-m-d H:i:s'))
            ->delete();
    }

    /** 恢复（delete_time 置回 NULL） */
    public static function restore($query, int|array $ids): int
    {
        return $query
            ->whereIn('id', array_map('intval', (array)$ids))
            ->useSoftDelete(self::FIELD, ['notnull', ''])
            ->update([self::FIELD => null]);
    }

    /** 彻底删除（绕过软删除，真 DELETE） */
    public static function force($query, int|array $ids): int
    {
        return $query
            ->whereIn('id', array_map('intval', (array)$ids))
            ->delete();
    }
}
