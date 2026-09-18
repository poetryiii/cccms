<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\LoginLog;
use plugin\cccms\support\Csv;
use support\Log;
use Throwable;
use Webman\Http\Response;

/**
 * 登录日志。
 *
 * 写入点只有一个：`AuthLogic::login()`（成功与失败都记）。
 * 与操作日志（`OperationLog` 中间件）的区别：登录接口是 `#[NoLogin]`，
 * 那时还没有用户上下文，中间件拿不到操作人，所以必须由登录逻辑自己记。
 *
 * 写日志失败**绝不影响登录本身**（吞掉异常并记 error 日志）。
 */
final class LoginLogLogic
{
    /** 导出上限：避免一次导出把内存打满 */
    private const EXPORT_LIMIT = 10000;

    /** 记录一条登录日志（由 AuthLogic 调用） */
    public static function record(int $userId, string $username, bool $success, string $message = ''): void
    {
        try {
            $request = function_exists('request') ? request() : null;

            LoginLog::create([
                'user_id'  => $userId,
                'username' => mb_substr($username, 0, 64),
                'status'   => $success ? 1 : 0,
                'message'  => mb_substr($message, 0, 255),
                'ip'       => (string)($request?->getRealIp() ?: ''),
                'ua'       => mb_substr((string)($request?->header('user-agent', '') ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            Log::error('login log failed: ' . $e->getMessage());
        }
    }

    public static function paginate(array $params): array
    {
        $query = self::filtered($params);

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** 导出 CSV（按当前筛选条件） */
    public static function export(array $params): Response
    {
        $rows = self::filtered($params)
            ->order('id', 'desc')
            ->limit(self::EXPORT_LIMIT)
            ->select()
            ->toArray();

        $data = array_map(static fn (array $row): array => [
            (string)($row['id'] ?? ''),
            (string)($row['username'] ?? ''),
            (int)($row['status'] ?? 0) === 1 ? '成功' : '失败',
            (string)($row['message'] ?? ''),
            (string)($row['ip'] ?? ''),
            (string)($row['create_time'] ?? ''),
        ], $rows);

        return Csv::download('登录日志', ['ID', '账号', '结果', '说明', 'IP', '时间'], $data);
    }

    public static function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return LoginLog::whereIn('id', $ids)->delete();
    }

    public static function clear(): int
    {
        return LoginLog::where('id', '>', 0)->delete();
    }

    /** @return \think\db\BaseQuery */
    private static function filtered(array $params)
    {
        $query = LoginLog::newScopedQuery();

        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        if (!empty($params['ip'])) {
            $query->where('ip', 'like', '%' . $params['ip'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        // 时间范围（按天）
        if (!empty($params['start'])) {
            $query->where('create_time', '>=', $params['start'] . ' 00:00:00');
        }
        if (!empty($params['end'])) {
            $query->where('create_time', '<=', $params['end'] . ' 23:59:59');
        }

        return $query;
    }
}
