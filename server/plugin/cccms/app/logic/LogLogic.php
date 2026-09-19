<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\OperationLog;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\Csv;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use support\Log;
use Throwable;
use Webman\Http\Response;

/**
 * 日志逻辑：操作日志与登录日志统一存 `sys_log`，登录记录固定 `path=/auth/login`
 * （中间件不记该路径，二者天然互斥），页面按「请求路径」筛选即可。
 *
 * 查询统一走 `OperationLog` 模型：它**参与**数据权限（仅本人 = 自己的日志、
 * 本部门 = 可见部门成员的日志，见模型里的 `$dataScope`），所以越权数据在列表与
 * 删除/清空上都自然不可见、不可动，这里不需要额外判定。
 *
 * 日志表没有软删除列，删除即物理删除。
 */
final class LogLogic
{
    /** 导出上限：避免一次导出把内存打满 */
    private const EXPORT_LIMIT = 10000;

    /** 链路视图上限：一次请求正常只有个位数记录，给足余量即可 */
    private const TRACE_LIMIT = 200;

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    /**
     * 记录一条登录日志（成功与失败都记）。
     *
     * 登录接口是 `#[NoLogin]`，那时还没有用户上下文，操作日志中间件拿不到操作人，
     * 所以由登录逻辑自己记；写成 `path='/auth/login'` 的 `sys_log` 记录，与操作日志同表。
     * 写日志失败**绝不影响登录本身**（吞掉异常并记 error 日志）。
     */
    public static function recordLogin(int $userId, string $username, bool $success, string $message = ''): void
    {
        try {
            $request = function_exists('request') ? request() : null;

            // 登录链路还没有用户上下文（登录接口是 #[NoLogin]），且登录日志必须**无条件**写入
            // （含失败尝试）；这里显式跳出数据权限：既没有范围可算，也不允许字段级规则剔除字段。
            OperationLog::withoutGlobalScope()->insert([
                'user_id'     => $userId,
                'username'    => mb_substr($username, 0, 64),
                'status'      => $success ? 1 : 0,
                'message'     => mb_substr($message, 0, 255),
                'method'      => 'POST',
                'path'        => '/auth/login',
                'title'       => '登录',
                'status_code' => $success ? 200 : 400,
                'ip'          => (string)($request?->getRealIp() ?: ''),
                'ua'          => mb_substr((string)($request?->header('user-agent', '') ?? ''), 0, 255),
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error('login log failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 查询 / 导出
    // ------------------------------------------------------------------

    public static function paginate(array $params): array
    {
        $query = self::filtered($params);

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** 导出 CSV（按当前筛选与数据范围） */
    public static function export(array $params): Response
    {
        $rows = self::filtered($params)->order('id', 'desc')->limit(self::EXPORT_LIMIT)->select()->toArray();

        $data = array_map(static fn (array $row): array => [
            (string)($row['id'] ?? ''),
            (string)($row['username'] ?? ''),
            (string)($row['title'] ?? ''),
            (int)($row['status'] ?? 1) === 1 ? '成功' : '失败',
            (string)($row['message'] ?? ''),
            (string)($row['method'] ?? ''),
            (string)($row['path'] ?? ''),
            (string)($row['node'] ?? ''),
            (string)($row['ip'] ?? ''),
            (string)($row['status_code'] ?? ''),
            (string)($row['cost'] ?? ''),
            (string)($row['trace_id'] ?? ''),
            (string)($row['create_time'] ?? ''),
        ], $rows);

        return Csv::download(
            '操作日志',
            ['ID', '账号', '操作', '结果', '说明', '方法', '路径', '节点', 'IP', '状态码', '耗时(ms)', '链路ID', '时间'],
            $data
        );
    }

    /**
     * 按 `trace_id` 聚合一次请求的全部日志（P2-6 链路视图）。
     *
     * 走同一份 `newScopedQuery()`：看不见的日志自然不出现（越权行不泄露）。
     * 按 `id` 升序返回，即请求内的时间顺序（同一请求的多条记录 id 递增）。
     * 未传 / 空 `trace_id` 抛 422，避免「不带条件」把整表捞出来。
     */
    public static function trace(string $traceId): array
    {
        $traceId = trim($traceId);
        if ($traceId === '') {
            throw new ApiException(I18n::t('log.trace_id_required'), 422);
        }

        $list = OperationLog::newScopedQuery()
            ->where('trace_id', $traceId)
            ->order('id', 'asc')
            ->limit(self::TRACE_LIMIT)
            ->select()->toArray();

        $failed = 0;
        $cost   = 0;
        foreach ($list as $row) {
            if ((int)($row['status'] ?? 1) !== 1) {
                $failed++;
            }
            $cost += (int)($row['cost'] ?? 0);
        }

        return [
            'trace_id' => $traceId,
            'total'    => count($list),
            'failed'   => $failed,
            'cost'     => $cost,
            'list'     => $list,
        ];
    }

    /** @return \think\db\BaseQuery */
    private static function filtered(array $params)
    {
        $query = OperationLog::newScopedQuery();

        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        // 请求方法：列头多选，值形如 `GET,POST`
        $methods = FilterInput::values($params['method'] ?? null);
        if ($methods !== []) {
            $query->whereIn('method', $methods);
        }
        if (!empty($params['path'])) {
            $query->where('path', 'like', '%' . $params['path'] . '%');
        }
        if (!empty($params['ip'])) {
            $query->where('ip', 'like', '%' . trim((string)$params['ip']) . '%');
        }
        // 语义化筛选：按权限节点或操作名找（比记 path 直观）
        if (!empty($params['node'])) {
            $query->where('node', 'like', '%' . $params['node'] . '%');
        }
        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        // 结果：1 成功 / 0 失败（列头多选，值形如 `1,0`）
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        // 链路 ID：拿到一次请求的报错 trace 后可直接检索
        if (!empty($params['trace_id'])) {
            $query->where('trace_id', trim((string)$params['trace_id']));
        }
        // 操作时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);
        if ($start !== '') {
            $query->where('create_time', '>=', $start);
        }
        if ($end !== '') {
            $query->where('create_time', '<=', $end);
        }

        return $query;
    }

    public static function delete(array $ids): void
    {
        if ($ids) {
            // 带作用域：只能删掉自己看得到的日志
            OperationLog::newScopedQuery()->whereIn('id', array_map('intval', $ids))->delete();
        }
    }

    public static function clear(): void
    {
        // 同理：清空的是「当前用户可见范围」内的日志，而不是全表
        OperationLog::newScopedQuery()->where('id', '>', 0)->delete();
    }
}
