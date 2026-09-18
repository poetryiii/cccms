<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\SqlFileRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use think\facade\Db;

/**
 * 表结构增量升级（幂等，可重复执行）。
 *
 * 新装环境直接导入 db/schema.sql 即可；本命令用于**已经建好的库**补新增的表 / 列 / 索引——
 * schema.sql 用的是 CREATE TABLE IF NOT EXISTS，对已存在的表不会补列，所以必须单独走这里。
 */
#[AsCommand('cccms:db-upgrade', '增量升级表结构（幂等，可重复执行）')]
class DbUpgradeCommand extends Command
{
    /** 新增表：表名(不含前缀) => 建表语句（%s 为表前缀占位） */
    private const TABLES = [
        'category' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%scategory` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `module`      varchar(32)  NOT NULL DEFAULT '' COMMENT '所属模块 dict/file',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '上级分类，0=顶级',
  `name`        varchar(64)  NOT NULL COMMENT '分类名',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通用分类'
SQL,
        'data_scope_table' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%sdata_scope_table` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `table_name`  varchar(64)  NOT NULL COMMENT '表名(不含前缀)',
  `label`       varchar(64)  NOT NULL DEFAULT '' COMMENT '语义名，留空则取表注释',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1受控 0停用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限受控表'
SQL,
        'notice' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%snotice` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `title`       varchar(128) NOT NULL COMMENT '标题',
  `type`        tinyint      NOT NULL DEFAULT 1 COMMENT '1通知 2公告',
  `level`       tinyint      NOT NULL DEFAULT 1 COMMENT '1普通 2重要',
  `content`     text         COMMENT '正文',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1已发布 0草稿',
  `scope`       tinyint      NOT NULL DEFAULT 0 COMMENT '投放范围 0全部用户 1指定部门 2指定角色 3指定用户',
  `publish_at`  datetime     DEFAULT NULL COMMENT '发布时间',
  `expire_at`   datetime     DEFAULT NULL COMMENT '过期时间(NULL=不过期)',
  `read_count`  int          NOT NULL DEFAULT 0 COMMENT '已读人数(冗余计数)',
  `create_by`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_scope` (`scope`),
  KEY `idx_publish_at` (`publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知公告'
SQL,
        'notice_target' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%snotice_target` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id`   bigint unsigned NOT NULL COMMENT '公告ID',
  `target_type` varchar(16)  NOT NULL DEFAULT 'user' COMMENT '目标类型 dept/role/user',
  `target_id`   bigint unsigned NOT NULL COMMENT '目标ID(部门/角色/用户)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_type_target` (`notice_id`, `target_type`, `target_id`),
  KEY `idx_type_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知公告定向投放'
SQL,
        'notice_read' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%snotice_read` (
  `id`        bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` bigint unsigned NOT NULL,
  `user_id`   bigint unsigned NOT NULL,
  `read_time` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_user` (`notice_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知公告已读'
SQL,
    ];

    /** 软删除列定义（NULL = 未删除） */
    private const SOFT_DELETE = "datetime DEFAULT NULL COMMENT '删除时间(NULL=未删除)'";

    /** 新增列：表名(不含前缀) => [列名 => 列定义] */
    private const COLUMNS = [
        'dict_type' => [
            'category_id' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '分类ID(0=未分类)' AFTER `type`",
            'delete_time' => self::SOFT_DELETE,
        ],
        'file' => [
            'category_id' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '分类ID(0=未分类)' AFTER `url`",
            'delete_time' => self::SOFT_DELETE,
        ],
        'log' => [
            'node'     => "varchar(128) NOT NULL DEFAULT '' COMMENT '权限节点 slug(路径语义化标识)' AFTER `path`",
            'title'    => "varchar(128) NOT NULL DEFAULT '' COMMENT '语义化操作名(取自权限注解)' AFTER `node`",
            'type'     => "varchar(16) NOT NULL DEFAULT 'operation' COMMENT '日志类型 operation操作 login登录' AFTER `title`",
            'status'   => "tinyint NOT NULL DEFAULT 1 COMMENT '1成功 0失败' AFTER `type`",
            'message'  => "varchar(255) NOT NULL DEFAULT '' COMMENT '结果说明(登录失败原因等)' AFTER `status`",
            'trace_id' => "varchar(32) NOT NULL DEFAULT '' COMMENT '请求链路 ID' AFTER `message`",
        ],
        // 定时任务增强：重叠保护 / 超时 / 失败重试 / 分组
        'crontab' => [
            'group_name'     => "varchar(32) NOT NULL DEFAULT '' COMMENT '任务分组' AFTER `name`",
            'overlap'        => "varchar(8) NOT NULL DEFAULT 'skip' COMMENT '重叠策略 skip/allow' AFTER `status`",
            'timeout'        => "int NOT NULL DEFAULT 0 COMMENT '超时秒数，0=不限' AFTER `overlap`",
            'retry_times'    => "tinyint NOT NULL DEFAULT 0 COMMENT '失败重试次数' AFTER `timeout`",
            'retry_interval' => "int NOT NULL DEFAULT 60 COMMENT '重试间隔(秒)' AFTER `retry_times`",
            'retry_left'     => "tinyint NOT NULL DEFAULT 0 COMMENT '剩余重试次数(运行时)' AFTER `retry_interval`",
            'retry_at'       => "datetime DEFAULT NULL COMMENT '下次重试时间(运行时)' AFTER `retry_left`",
            'running'        => "tinyint NOT NULL DEFAULT 0 COMMENT '是否运行中(运行时)' AFTER `retry_at`",
            'running_at'     => "datetime DEFAULT NULL COMMENT '本次开始运行时间(运行时)' AFTER `running`",
        ],
        'crontab_log' => [
            'source' => "varchar(16) NOT NULL DEFAULT 'cron' COMMENT '触发来源 cron/retry/manual' AFTER `status`",
        ],
        'data_rule' => [
            'bind_mode'  => "varchar(8) NOT NULL DEFAULT 'or' COMMENT '绑定组合方式 or=任一命中 and=已填写的全部命中' AFTER `role_id`",
            'table_name' => "varchar(64) NOT NULL DEFAULT '' COMMENT '目标表(不含前缀)，空=不限表' AFTER `bind_mode`",
            'value_type' => "varchar(16) NOT NULL DEFAULT 'static' COMMENT '取值类型 static静态 dynamic动态变量' AFTER `value`",
            'delete_time' => self::SOFT_DELETE,
        ],
        // 通知公告定向投放
        'notice' => [
            'scope' => "tinyint NOT NULL DEFAULT 0 COMMENT '投放范围 0全部用户 1指定部门 2指定角色 3指定用户' AFTER `status`",
        ],
        // 软删除（回收站）
        'menu'      => ['delete_time' => self::SOFT_DELETE],
        'user'      => ['delete_time' => self::SOFT_DELETE],
        'role'      => ['delete_time' => self::SOFT_DELETE],
        'dept'      => ['delete_time' => self::SOFT_DELETE],
        'post'      => ['delete_time' => self::SOFT_DELETE],
        'category'  => ['delete_time' => self::SOFT_DELETE],
        'dict_data' => ['delete_time' => self::SOFT_DELETE],
        'crontab'   => ['delete_time' => self::SOFT_DELETE],
    ];

    /**
     * 时间戳列统一交给数据库自动写入（幂等）。
     *
     * 业务层统一用 Db::name() 查询构造器，think-orm 的模型自动时间戳不生效，
     * 所以用列默认值兜底，保证任何写入路径都不会漏掉 create_time / update_time。
     */
    private const TIMESTAMPS = [
        'user', 'role', 'menu', 'dept', 'post', 'data_rule', 'data_scope_table',
        'category', 'dict_type', 'dict_data', 'config', 'crontab',
    ];

    /** 新增索引：表名(不含前缀) => [索引名 => 列定义] */
    private const INDEXES = [
        'dict_type' => ['idx_category' => '`category_id`'],
        'file'      => ['idx_category' => '`category_id`'],
        'log'       => [
            'idx_node'     => '`node`',
            'idx_trace_id' => '`trace_id`',
            'idx_type'     => '`type`',
        ],
        'crontab'   => ['idx_retry' => '`retry_left`, `retry_at`'],
        'notice'    => ['idx_scope' => '`scope`'],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 注意：Db::getConfig('connections.mysql.prefix') 取不到，必须走连接实例
        $prefix = (string)Db::connect()->getConfig('prefix');
        $tables = 0;
        $cols   = 0;
        $idxs   = 0;
        $stamps = 0;

        foreach (self::TABLES as $name => $sql) {
            if ($this->exists($prefix . $name)) {
                continue;
            }
            Db::execute(sprintf($sql, $prefix));
            $output->writeln("  <info>建表</info> {$prefix}{$name}");
            $tables++;
        }

        foreach (self::COLUMNS as $name => $columns) {
            $table = $prefix . $name;
            if (!$this->exists($table)) {
                $output->writeln("  <comment>跳过</comment> {$table}（表不存在）");
                continue;
            }
            $current = $this->columns($table);
            foreach ($columns as $column => $definition) {
                if (isset($current[$column])) {
                    continue;
                }
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                $output->writeln("  <info>加列</info> {$table}.{$column}");
                $cols++;
            }
        }

        foreach (self::INDEXES as $name => $indexes) {
            $table = $prefix . $name;
            if (!$this->exists($table)) {
                continue;
            }
            $current = $this->indexes($table);
            foreach ($indexes as $index => $definition) {
                if (isset($current[$index])) {
                    continue;
                }
                Db::execute("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$definition})");
                $output->writeln("  <info>加索引</info> {$table}.{$index}");
                $idxs++;
            }
        }

        // 时间戳列：统一改成「数据库自动写入」，已正确的跳过
        foreach (self::TIMESTAMPS as $name) {
            $table = $prefix . $name;
            if (!$this->exists($table)) {
                continue;
            }
            $meta = $this->columnMeta($table);
            foreach (['create_time' => false, 'update_time' => true] as $column => $onUpdate) {
                if (!isset($meta[$column])) {
                    continue;
                }
                $default = strtoupper((string)($meta[$column]['Default'] ?? ''));
                $extra   = strtolower((string)($meta[$column]['Extra'] ?? ''));
                if (str_contains($default, 'CURRENT_TIMESTAMP') && (!$onUpdate || str_contains($extra, 'on update'))) {
                    continue;
                }
                $definition = 'datetime NULL DEFAULT CURRENT_TIMESTAMP' . ($onUpdate ? ' ON UPDATE CURRENT_TIMESTAMP' : '');
                Db::execute("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
                $output->writeln("  <info>时间戳</info> {$table}.{$column}");
                $stamps++;
            }
        }

        // 迁移旧登录日志表 → sys_log（一次性：迁移后 DROP，天然幂等）。
        // 登录日志与操作日志合并到同一张 sys_log，用 type='login' 区分。
        if ($this->exists($prefix . 'login_log')) {
            try {
                Db::execute(
                    "INSERT INTO `{$prefix}log`
                     (user_id, username, type, status, message, method, path, title, ip, ua, create_time)
                     SELECT user_id, username, 'login', status, message, 'POST', '/auth/login', '登录', ip, ua, create_time
                     FROM `{$prefix}login_log`"
                );
                Db::execute("DROP TABLE `{$prefix}login_log`");
                $output->writeln('  <info>迁移登录日志</info> login_log → log（合并到 sys_log）');
            } catch (\Throwable $e) {
                // 迁移失败不阻断其余升级，但要显式报出来，避免静默丢数据
                $output->writeln('  <error>登录日志迁移失败</error>：' . $e->getMessage());
            }
        }

        $output->writeln("<info>升级完成：建表 {$tables}，加列 {$cols}，加索引 {$idxs}，时间戳 {$stamps}</info>");

        // 业务插件建表：执行 plugin/*/db/schema.sql（幂等；cccms 自身由上面的增量逻辑负责）
        $pluginStatements = 0;
        foreach (SqlFileRunner::pluginSchemaFiles() as $file) {
            $plugin = SqlFileRunner::pluginNameOf($file);
            try {
                $n = SqlFileRunner::run($file);
                $pluginStatements += $n;
                $output->writeln("  <info>插件 schema</info> {$plugin}（{$n} 条）");
            } catch (\Throwable $e) {
                $output->writeln("  <error>插件 schema 失败</error> {$plugin}：{$e->getMessage()}");
                return Command::FAILURE;
            }
        }
        if ($pluginStatements > 0) {
            $output->writeln("<info>业务插件表结构：共执行 {$pluginStatements} 条语句</info>");
        }

        return Command::SUCCESS;
    }

    /** @return array<string,array<string,mixed>> 列名 => SHOW COLUMNS 原始行 */
    private function columnMeta(string $table): array
    {
        $out = [];
        foreach (Db::query("SHOW COLUMNS FROM `{$table}`") as $row) {
            $out[(string)$row['Field']] = $row;
        }

        return $out;
    }

    private function exists(string $table): bool
    {
        // 用 information_schema 而不是 `SHOW TABLES LIKE ?`——后者不支持参数绑定
        return Db::query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    /** @return array<string,true> 列名集合 */
    private function columns(string $table): array
    {
        $out = [];
        foreach (Db::query("SHOW COLUMNS FROM `{$table}`") as $row) {
            $out[(string)$row['Field']] = true;
        }

        return $out;
    }

    /** @return array<string,true> 索引名集合 */
    private function indexes(string $table): array
    {
        $out = [];
        foreach (Db::query("SHOW INDEX FROM `{$table}`") as $row) {
            $out[(string)$row['Key_name']] = true;
        }

        return $out;
    }
}
