<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/**
 * 数据权限「受控表」逻辑（sys_data_scope_table）。
 *
 * 「某张表能否做数据权限」本质上取决于业务 Logic 有没有调用 DataScope，
 * 因此这里是一份**登记表**：列进来的表才会出现在规则页的「目标表」候选里，
 * 其余系统表 / 业务表一律隐藏，避免配出一条永远不会生效的规则。
 *
 * 语义名优先取登记时手填的 label，留空则回退到库表注释。
 */
final class DataScopeTableLogic
{
    /** 受控表列表 + 可添加的表（未登记的库表） */
    public static function index(): array
    {
        $rows   = Db::name('data_scope_table')->order('id', 'asc')->select()->toArray();
        $tables = self::dbTables();

        $list = [];
        foreach ($rows as $row) {
            $name = (string)$row['table_name'];
            $meta = $tables[$name] ?? null;

            // 语义名：手填优先，其次库表注释，最后表名
            $label = (string)$row['label'];
            if ($label === '') {
                $label = (string)($meta['comment'] ?? '') ?: $name;
            }

            $list[] = [
                'id'          => (int)$row['id'],
                'table_name'  => $name,
                'label'       => $label,
                'status'      => (int)$row['status'],
                'remark'      => (string)$row['remark'],
                'field_count' => (int)($meta['field_count'] ?? 0),
                // 表被删 / 改名后要能看出来，否则规则会「莫名不生效」
                'exists'      => $meta !== null,
            ];
        }

        $used      = array_map(static fn ($row) => (string)$row['table_name'], $rows);
        $available = [];
        foreach ($tables as $bare => $meta) {
            if (in_array($bare, $used, true)) {
                continue;
            }
            $available[] = [
                'table' => $bare,
                'full'  => $meta['full'],
                'label' => $meta['comment'] !== '' ? $meta['comment'] : $bare,
            ];
        }

        return ['list' => $list, 'available' => $available];
    }

    public static function save(array $data): int
    {
        $table  = trim((string)($data['table_name'] ?? ''));
        $tables = self::dbTables();

        if ($table === '' || !isset($tables[$table])) {
            throw new ApiException('表不存在：' . ($table === '' ? '(空)' : $table), 422);
        }
        if (Db::name('data_scope_table')->where('table_name', $table)->count() > 0) {
            throw new ApiException('该表已在受控表内', 422);
        }

        return (int)Db::name('data_scope_table')->insertGetId([
            'table_name'  => $table,
            'label'       => self::label($data['label'] ?? ''),
            'status'      => self::status($data['status'] ?? 1),
            'remark'      => trim((string)($data['remark'] ?? '')),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        self::assertExists($id);

        $update = [];
        if (array_key_exists('label', $data)) {
            $update['label'] = self::label($data['label']);
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = self::status($data['status']);
        }
        if (array_key_exists('remark', $data)) {
            $update['remark'] = trim((string)$data['remark']);
        }
        if ($update === []) {
            return;
        }

        $update['update_time'] = date('Y-m-d H:i:s');
        Db::name('data_scope_table')->where('id', $id)->update($update);
    }

    /**
     * 移除受控表。
     *
     * 规则本身不删：重新登记后即可恢复生效。返回该表上已有的规则数，
     * 让界面能明确提示「这些规则已暂停生效」。
     */
    public static function delete(int $id): int
    {
        $row = self::assertExists($id);

        $rules = (int)SoftDelete::apply(Db::name('data_rule'))->where('table_name', (string)$row['table_name'])->count();
        Db::name('data_scope_table')->where('id', $id)->delete();

        return $rules;
    }

    /** 库中所有表（不含前缀表名 => 元信息） */
    private static function dbTables(): array
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        $rows   = Db::query(
            'SELECT t.TABLE_NAME, t.TABLE_COMMENT, COUNT(c.COLUMN_NAME) AS field_count
               FROM information_schema.TABLES t
               LEFT JOIN information_schema.COLUMNS c
                 ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
              WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME LIKE ?
              GROUP BY t.TABLE_NAME, t.TABLE_COMMENT
              ORDER BY t.TABLE_NAME',
            [$prefix . '%']
        );

        $out = [];
        foreach ($rows as $row) {
            $full = (string)$row['TABLE_NAME'];
            $bare = substr($full, strlen($prefix));
            if ($bare === '') {
                continue;
            }
            $out[$bare] = [
                'table'       => $bare,
                'full'        => $full,
                'comment'     => trim((string)$row['TABLE_COMMENT']),
                'field_count' => (int)$row['field_count'],
            ];
        }

        return $out;
    }

    private static function label(mixed $value): string
    {
        return mb_substr(trim((string)$value), 0, 64);
    }

    private static function status(mixed $value): int
    {
        return (int)$value === 1 ? 1 : 0;
    }

    private static function assertExists(int $id): array
    {
        $row = Db::name('data_scope_table')->where('id', $id)->find();
        if (!$row) {
            throw new ApiException('受控表不存在', 404);
        }

        return $row;
    }
}
