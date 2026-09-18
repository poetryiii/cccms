<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Notice;
use plugin\cccms\app\model\NoticeRead;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\UserContext;
use think\db\BaseQuery;

/**
 * 通知公告。
 *
 * 两套入口：
 *   - **管理侧**（需权限节点 `cccms:notice:*`）：全量增删改查；
 *   - **阅读侧**（登录即可）：只看「已发布 + 未过期」的，并维护已读状态。
 *
 * 「已读人数」用 `sys_notice.read_count` 冗余计数（列表不必实时 count），
 * 只有在「首次已读」时才 +1，避免重复自增。
 */
final class NoticeLogic
{
    /** 管理侧可写字段白名单 */
    private const FIELDS = ['title', 'type', 'level', 'content', 'status', 'publish_at', 'expire_at'];

    // ------------------------------------------------------------------
    // 管理侧
    // ------------------------------------------------------------------

    public static function paginate(array $params): array
    {
        $query = Notice::newScopedQuery();

        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        if (isset($params['type']) && $params['type'] !== '') {
            $query->where('type', (int)$params['type']);
        }
        if (isset($params['level']) && $params['level'] !== '') {
            $query->where('level', (int)$params['level']);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('level', 'desc')->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public static function read(int $id): array
    {
        $notice = Notice::where('id', $id)->find();
        if (!$notice) {
            throw new ApiException('通知公告不存在', 404);
        }

        return $notice->toArray();
    }

    public static function create(array $data, UserContext $operator): int
    {
        $payload = self::prepare($data);
        $payload['create_by']  = $operator->id;
        $payload['read_count'] = 0;
        // 已发布但没填发布时间的，视为「立即发布」
        if ((int)$payload['status'] === 1 && empty($payload['publish_at'])) {
            $payload['publish_at'] = date('Y-m-d H:i:s');
        }

        return (int)Notice::withoutGlobalScope()->insertGetId($payload);
    }

    public static function update(int $id, array $data): void
    {
        self::read($id);
        $payload = self::prepare($data, true);
        if ((int)($payload['status'] ?? 0) === 1 && empty($payload['publish_at'])) {
            $payload['publish_at'] = date('Y-m-d H:i:s');
        }

        Notice::where('id', $id)->update($payload);
    }

    public static function delete(int $id): void
    {
        self::read($id);
        // 软删除：进回收站（见 RecycleLogic 的 notice 类型）
        Notice::destroy($id);
        NoticeRead::where('notice_id', $id)->delete();
    }

    // ------------------------------------------------------------------
    // 阅读侧（登录即可）
    // ------------------------------------------------------------------

    /** 我的消息分页（已发布 + 未过期） */
    public static function myPaginate(int $userId, array $params): array
    {
        $query = self::published();
        if (!empty($params['only_unread'])) {
            $readIds = NoticeRead::where('user_id', $userId)->column('notice_id');
            if ($readIds) {
                $query->whereNotIn('id', array_map('intval', $readIds));
            }
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $rows  = $query->page($page, $limit)->order('level', 'desc')->order('id', 'desc')->select()->toArray();

        // 标记已读状态（一次查询，避免逐行查库）
        $ids     = array_map('intval', array_column($rows, 'id'));
        $readIds = $ids === []
            ? []
            : array_map('intval', NoticeRead::where('user_id', $userId)->whereIn('notice_id', $ids)->column('notice_id'));
        foreach ($rows as &$row) {
            $row['is_read'] = in_array((int)$row['id'], $readIds, true);
        }
        unset($row);

        return ['total' => $total, 'list' => $rows];
    }

    /** 未读数量（顶栏角标） */
    public static function unreadCount(int $userId): int
    {
        $total = self::published()->count();
        if ($total === 0) {
            return 0;
        }

        return max(0, $total - NoticeRead::where('user_id', $userId)->count());
    }

    public static function markRead(int $userId, int $noticeId): void
    {
        $notice = self::published()->where('id', $noticeId)->find();
        if (!$notice) {
            throw new ApiException('通知公告不存在或已过期', 404);
        }

        if (NoticeRead::where('notice_id', $noticeId)->where('user_id', $userId)->find()) {
            return;   // 已读过，不重复计数
        }

        NoticeRead::create([
            'notice_id' => $noticeId,
            'user_id'   => $userId,
            'read_time' => date('Y-m-d H:i:s'),
        ]);

        // 冗余计数：只在首次已读时 +1
        Notice::where('id', $noticeId)->inc('read_count')->update();
    }

    /** 全部标记已读 */
    public static function markAllRead(int $userId): int
    {
        $ids = array_map('intval', self::published()->column('id'));
        if ($ids === []) {
            return 0;
        }

        $read = array_map('intval', NoticeRead::where('user_id', $userId)->whereIn('notice_id', $ids)->column('notice_id'));
        $todo = array_values(array_diff($ids, $read));
        if ($todo === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($todo as $id) {
            NoticeRead::create(['notice_id' => $id, 'user_id' => $userId, 'read_time' => $now]);
        }
        Notice::whereIn('id', $todo)->inc('read_count')->update();

        return count($todo);
    }

    // ------------------------------------------------------------------

    /** 已发布 + 未过期的公告查询 */
    private static function published(): BaseQuery
    {
        $now = date('Y-m-d H:i:s');

        return Notice::newScopedQuery()
            ->where('status', 1)
            ->where(function ($query) use ($now): void {
                $query->whereNull('publish_at')->whereOr('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expire_at')->whereOr('expire_at', '>', $now);
            });
    }

    /**
     * 字段白名单整理。
     *
     * @param bool $partial 更新场景：只保留显式传入的字段
     * @return array<string,mixed>
     */
    private static function prepare(array $data, bool $partial = false): array
    {
        $payload = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        // 标题：新增必填；更新时若显式传入则不能为空
        if (!$partial || array_key_exists('title', $payload)) {
            $title = trim((string)($payload['title'] ?? ''));
            if ($title === '') {
                throw new ApiException('标题不能为空', 422);
            }
            $payload['title'] = $title;
        }

        // 空字符串的时间字段视为「不设置」，否则 MySQL 严格模式会报错
        foreach (['publish_at', 'expire_at'] as $timeField) {
            if (array_key_exists($timeField, $payload) && $payload[$timeField] === '') {
                $payload[$timeField] = null;
            }
        }

        return $payload;
    }
}
