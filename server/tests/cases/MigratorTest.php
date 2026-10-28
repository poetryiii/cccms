<?php

declare(strict_types=1);

use plugin\cccms\support\Migrator;
use plugin\cccms\support\SqlFileRunner;

/**
 * 结构迁移（`plugin/{插件}/db/migrations/*.sql`）的执行约定。
 *
 * 只测不依赖数据库的部分：错误判定、版本号规范、schema 与迁移是否同步。
 * 真正的 DDL 执行由 `cccms:db-upgrade` 在真实库上验证。
 */
return static function (): void {
    suite('结构迁移约定');

    /*
     * 「结构已存在」的判定口径。
     *
     * 这条用例来自线上事故：迁移 20261026_100000_tenant_domain_file_public 的第 2 条语句
     * 同时 ADD COLUMN 与 ADD CONSTRAINT，新装环境里两样都已由 schema.sql 建好，
     * MySQL 先校验约束名、回的是 3822 `Duplicate check constraint name`，
     * 而容错规则只认 1060/1061，于是整条迁移被判失败、后续迁移全部停住。
     */
    test('「结构已存在」判定：四个重复错误码都要认，数据冲突不能认', function (): void {
        // 线上真实报文（PDO 把错误码塞在 message 里，不是 getCode()）
        $tolerated = [
            "SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'is_public'",
            "SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'uk_domain'",
            "SQLSTATE[HY000]: General error: 1826 Duplicate foreign key constraint name 'fk_x'",
            "SQLSTATE[HY000]: General error: 3822 Duplicate check constraint name 'ck_file_public'.",
        ];
        foreach ($tolerated as $message) {
            ok(SqlFileRunner::isExistingStructureError($message), "应当按「已生效」跳过：{$message}");
        }

        // 这些是真错误：跳过等于把库留在半残状态
        $rejected = [
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'PRIMARY'",
            "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'sem.sys_not_exist' doesn't exist",
            "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax",
            "SQLSTATE[HY000]: General error: 1215 Cannot add foreign key constraint",
        ];
        foreach ($rejected as $message) {
            ok(!SqlFileRunner::isExistingStructureError($message), "不能当成「已生效」吞掉：{$message}");
        }
    });

    test('迁移文件命名：版本号合法且全局唯一（字典序 = 执行序）', function (): void {
        $items = Migrator::discover(dirname(__DIR__, 2));
        ok(count($items) > 0, '至少应发现一个迁移文件，否则这条护栏等于没跑');

        $seen = [];
        foreach ($items as $item) {
            ok(
                Migrator::isValidVersion($item['version']),
                "迁移文件名不合规（应为 YYYYMMDD_HHMMSS_描述.sql）：{$item['file']}"
            );
            ok(!isset($seen[$item['version']]), "版本号重复：{$item['version']}（{$item['file']}）");
            $seen[$item['version']] = true;
        }
    });

    /*
     * schema.sql 与迁移文件必须成对维护：
     * schema.sql 只对**缺失的表**生效，所以「新装」与「旧库升级」要各有一份；
     * 只改一边的结果是——新装正常、旧库少列（运行时 Unknown column），或者反过来。
     */
    test('schema 与迁移同步：sys_file.is_public 与 ck_file_public 两边都有', function (): void {
        $base   = dirname(__DIR__, 2) . '/plugin/cccms/db';
        $schema = (string)file_get_contents($base . '/schema.sql');
        $files  = glob($base . '/migrations/*.sql') ?: [];
        $all    = '';
        foreach ($files as $file) {
            $all .= (string)file_get_contents($file);
        }

        foreach (['is_public', 'ck_file_public'] as $needle) {
            contains($needle, $schema, "schema.sql 缺少 {$needle}（新装环境会少这一列/约束）");
            contains($needle, $all, "迁移文件缺少 {$needle}（旧库升级会少这一列/约束）");
        }

        // 同理：租户自定义域名是同一批迁移加的两列
        foreach (['`domain`', 'uk_domain'] as $needle) {
            contains($needle, $schema, "schema.sql 缺少 {$needle}");
            contains($needle, $all, "迁移文件缺少 {$needle}");
        }
    });
};
