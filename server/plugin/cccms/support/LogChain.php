<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use think\facade\Db;
use Throwable;

/**
 * 审计日志的**链式哈希**（防篡改）+ 唯一的 `sys_log` 写入入口。
 *
 * ## 为什么要做
 *
 * `sys_log` 是合规审计的凭证，但应用只是普通 `INSERT`：任何拿到库权限的人
 * （DBA、误操作、被拿下的运维机）都能 `UPDATE` 掉"我改过这条记录"或 `DELETE` 掉整段，
 * 而**事后无法区分**"本来就是这样"与"被人改过"。归档解决的是容量，不是可信。
 *
 * ## 算法
 *
 * 每行存两个字段：
 *   - `prev_hash`：**上一条**记录的 `row_hash`（链）；
 *   - `row_hash` ：`sha256(prev_hash + "\n" + 规范化后的行内容)`。
 *
 * 规范化（`normalize()`）把 16 个字段按**固定顺序**、**固定类型**、**固定长度**取值，
 * 再 `json_encode`，因此"同一行内容"在写入侧与校验侧得到的字节完全一致 ——
 * 否则光是 PDO 把 int 读成字符串就会让校验全线误报。
 *
 * 链尾（`tail_id` / `tail_hash`）与校验起点（`anchor_id`）存在单行表 `sys_log_chain` 里：
 * 只靠"查最大 id"无法发现**尾行被删**，而删除尾行正是最常见的掩盖手段。
 *
 * ## 并发正确性
 *
 * 先读链尾、再插入、再推进链尾，这三步必须在同一个事务里并用 `SELECT ... FOR UPDATE`
 * 锁住链尾行，否则两个并发请求会读到同一个 `prev_hash` 写出**分叉链**，
 * 校验时凭空多出断点。锁只覆盖"算哈希 + 插入"这一段，不覆盖整个请求。
 *
 * ## 失败时的态度：fail-open
 *
 * 链式哈希是**加固能力**，不是业务前置条件：表还没建（升级未执行）、
 * 库偶发不可用、锁等待超时，都不该让操作日志丢失（丢日志比少一层校验更糟）。
 * 因此任何异常都降级为"不带哈希的普通插入"，并记一条 error 日志。
 * 降级写入的行 `row_hash` 为空，校验命令会把它们标为「未纳入链」而不是「被篡改」。
 */
final class LogChain
{
    /** 单行链状态表的固定主键 */
    public const CHAIN_ID = 1;

    /** 链表不可用时的重检间隔（秒）：避免每次写日志都白跑一次失败的查询 */
    private const RECHECK_SECONDS = 60;

    /**
     * 参与哈希的字段：`字段 => [类型, 长度, 默认值]`。
     *
     * **顺序即哈希顺序**，改动这里等于让历史行全部失效 —— 新增字段时必须同步
     * 写一条 `cccms:db-upgrade` 的说明，并接受"旧行需要重新锚定"。
     */
    private const FIELDS = [
        'user_id'     => ['int', 0, 0],
        'username'    => ['str', 64, ''],
        'method'      => ['str', 16, ''],
        'path'        => ['str', 255, ''],
        'node'        => ['str', 128, ''],
        'title'       => ['str', 128, ''],
        'status'      => ['int', 0, 1],
        'message'     => ['str', 255, ''],
        'trace_id'    => ['str', 32, ''],
        'ip'          => ['str', 64, ''],
        'ua'          => ['str', 255, ''],
        'params'      => ['str', 8000, ''],
        'result'      => ['str', 8000, ''],
        'status_code' => ['int', 0, 200],
        'cost'        => ['int', 0, 0],
        'create_time' => ['str', 19, ''],
    ];

    private static ?bool $ready = null;

    private static int $checkedAt = 0;

    /**
     * 写入一条日志（**唯一入口**：中间件与登录逻辑都走这里）。
     *
     * @param array<string,mixed> $row 未规范化的行数据（缺字段自动补默认值）
     */
    public static function write(array $row): int
    {
        $data = self::normalize($row);

        if (!self::ready()) {
            return self::plainInsert($data);
        }

        try {
            Db::startTrans();

            // 锁住链尾行：并发写入串行化，避免两条记录从同一个 prev_hash 分叉
            $head = Db::name('log_chain')->where('id', self::CHAIN_ID)->lock(true)->find();
            if (!$head) {
                Db::name('log_chain')->insert([
                    'id'          => self::CHAIN_ID,
                    'tail_id'     => 0,
                    'tail_hash'   => '',
                    'anchor_id'   => 0,
                    'anchor_hash' => '',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                $head = ['tail_hash' => '', 'anchor_id' => 0];
            }

            $prev = (string)($head['tail_hash'] ?? '');

            // ⚠️ 必须先算哈希再挂 `prev_hash`：`normalize()` 只产出 16 个业务字段，
            // 若把 `prev_hash` 混进参与哈希的载荷，校验侧（只 normalize 业务字段）
            // 永远算不出同一个值 —— 表现为「所有行都被判为已篡改」。
            $rowHash = self::hash($prev, $data);

            $data['prev_hash'] = $prev;
            $data['row_hash']  = $rowHash;

            $id = (int)Db::name('log')->insertGetId($data);

            $update = [
                'tail_id'     => $id,
                'tail_hash'   => $rowHash,
                'update_time' => date('Y-m-d H:i:s'),
            ];
            // 首次写入（或历史行全被清空过）时把校验起点一并定在这一行
            if ((int)($head['anchor_id'] ?? 0) <= 0) {
                $update['anchor_id']   = $id;
                $update['anchor_hash'] = $rowHash;
            }
            Db::name('log_chain')->where('id', self::CHAIN_ID)->update($update);

            Db::commit();

            return $id;
        } catch (Throwable $e) {
            try {
                Db::rollback();
            } catch (Throwable) {
                // 忽略
            }
            self::$ready = false;
            self::$checkedAt = time();
            Log::error('log chain write failed, fallback to plain insert: ' . $e->getMessage());

            return self::plainInsert($data);
        }
    }

    /**
     * 重新锚定：应用自己删过日志（管理员删除 / 清空 / 归档 / 定时清理）之后调用。
     *
     * 把校验起点移到**当前第一条带哈希的行**，并同步链尾。
     * 这样"合法删除"不会在后续校验里表现为断点；而链中间的删除**仍会**被报出来
     * （那是无法与篡改区分的情况，交给人工按 `cccms:log-verify` 报出的 id 核对）。
     */
    public static function reanchor(): void
    {
        if (!self::ready()) {
            return;
        }

        try {
            $first = Db::name('log')->where('row_hash', '<>', '')->order('id', 'asc')->find();
            $last  = Db::name('log')->where('row_hash', '<>', '')->order('id', 'desc')->find();

            // 单行状态不存在时先建出来（例如「升级后第一次操作就是清理日志」）
            if (!Db::name('log_chain')->where('id', self::CHAIN_ID)->find()) {
                Db::name('log_chain')->insert([
                    'id'          => self::CHAIN_ID,
                    'tail_id'     => 0,
                    'tail_hash'   => '',
                    'anchor_id'   => 0,
                    'anchor_hash' => '',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }

            Db::name('log_chain')->where('id', self::CHAIN_ID)->update([
                'anchor_id'   => (int)($first['id'] ?? 0),
                'anchor_hash' => (string)($first['row_hash'] ?? ''),
                'tail_id'     => (int)($last['id'] ?? 0),
                'tail_hash'   => (string)($last['row_hash'] ?? ''),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error('log chain reanchor failed: ' . $e->getMessage());
        }
    }

    /** 链状态（tail / anchor），表不存在时返回 null */
    public static function state(): ?array
    {
        try {
            $row = Db::name('log_chain')->where('id', self::CHAIN_ID)->find();

            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 规范化为固定顺序 / 类型 / 长度的字段集（**写入与校验共用**，保证字节一致）。
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function normalize(array $row): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [$type, $limit, $default]) {
            $value = $row[$field] ?? null;

            if ($type === 'int') {
                $out[$field] = $value === null || $value === '' ? $default : (int)$value;
                continue;
            }

            $value = $value === null ? '' : (string)$value;
            if ($field === 'create_time' && $value === '') {
                $value = date('Y-m-d H:i:s');
            }
            // 按**字符**截断而不是字节：utf8mb4 下 varchar(n) 的 n 就是字符数，
            // 按字节截会让中文记录被库二次截断，导致写入侧与校验侧的字节不一致
            $out[$field] = $limit > 0 ? mb_substr($value, 0, $limit) : $value;
        }

        return $out;
    }

    /**
     * 计算行哈希。
     *
     * @param array<string,mixed> $normalized 已规范化的行
     */
    public static function hash(string $prevHash, array $normalized): string
    {
        return hash(
            'sha256',
            $prevHash . "\n" . (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** 链表 / 列是否就绪（升级后无需重启：负结论只缓存 60 秒） */
    private static function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }
        if (self::$ready === false && time() - self::$checkedAt < self::RECHECK_SECONDS) {
            return false;
        }

        self::$checkedAt = time();

        try {
            Db::name('log_chain')->where('id', self::CHAIN_ID)->count();
            self::$ready = true;
        } catch (Throwable) {
            self::$ready = false;
        }

        return self::$ready;
    }

    /**
     * 降级写入：不带哈希的普通插入。
     *
     * 刻意把 `prev_hash` / `row_hash` 写成空串而不是省略 —— 省略会落到列默认值，
     * 哪种结果都一样，但显式写空串让"这行没进链"在数据层面一眼可见。
     *
     * @param array<string,mixed> $data
     */
    private static function plainInsert(array $data): int
    {
        $data['prev_hash'] = '';
        $data['row_hash']  = '';

        return (int)Db::name('log')->insertGetId($data);
    }
}
