<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\SqlFileRunner;
use plugin\cccms\support\storage\StorageDriver;
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
        'tenant' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%stenant` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL COMMENT '租户名称',
  `code`        varchar(64)  NOT NULL COMMENT '租户标识(全局唯一)',
  `contact`     varchar(64)  NOT NULL DEFAULT '' COMMENT '联系人',
  `phone`       varchar(32)  NOT NULL DEFAULT '' COMMENT '联系电话',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `expire_at`   datetime     DEFAULT NULL COMMENT '到期时间(NULL=不过期)',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户表'
SQL,
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
        'crontab_retry' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `%scrontab_retry` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id`  bigint unsigned NOT NULL COMMENT '任务ID',
  `attempt`     tinyint        NOT NULL DEFAULT 1 COMMENT '第几次重试(1..retry_times)',
  `retry_at`    datetime       NOT NULL COMMENT '计划重试时间',
  `create_time` datetime       NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`retry_at`),
  KEY `idx_crontab` (`crontab_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务独立重试队列'
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

    /**
     * 列默认值修正：表名(不含前缀) => [列名 => [完整列定义, 期望默认值]]。
     *
     * 只改列的**默认值**，不动已有行的数据 —— 升级不会把线上已配好的档位改掉。
     * 用于「漏配兜底方向」纠偏：`role.data_scope` 原默认 1（全部数据），
     * 档位不继承父角色、靠「取最宽松」生效，漏配即等于静默放行全库；
     * 改为默认 4（仅本人），让新建角色的兜底落在最窄范围。
     */
    private const COLUMN_DEFAULTS = [
        'role' => [
            'data_scope' => [
                "tinyint NOT NULL DEFAULT 4 COMMENT '数据范围 1全部 2本部门及以下 3本部门 4仅本人 5自定义'",
                '4',
            ],
        ],
    ];

    /** 软删除列定义（NULL = 未删除） */
    private const SOFT_DELETE = "datetime DEFAULT NULL COMMENT '删除时间(NULL=未删除)'";

    /**
     * 新增配置项（老库补插）：配置名 => [标题, 控件类型, 默认值, 分组, 排序, 说明, 选项?]。
     *
     * 新装环境由 db/seed.sql 建好；已建好的库不会重跑 seed，必须在这里补。
     * 用 INSERT IGNORE：已存在则跳过，不覆盖管理员改过的值。
     * 第 7 项（可选）为 select / radio 的选项 JSON，缺省为 NULL。
     */
    private const NEW_CONFIGS = [
        'security.login_fail_ip_limit' => [
            '同 IP 失败上限', 'input-number', '20', '安全', 3,
            '同一 IP 连续登录失败达到该次数后限流该 IP（不锁账号，避免误伤同出口 IP 的同事）；0 表示关闭',
        ],
        'security.rate_limit_enable' => [
            '接口限流开关', 'switch', '1', '安全', 9,
            '开启后按「每用户每路由」限流，接口被高频刷取时返回 429',
        ],
        'security.rate_limit_limit' => [
            '普通接口次数', 'input-number', '60', '安全', 10,
            '每用户每路由每分钟允许的调用次数；0 表示不限制',
        ],
        'security.rate_limit_heavy_limit' => [
            '重接口次数', 'input-number', '5', '安全', 11,
            '导出/导入/代码生成等重接口每用户每路由每分钟次数；0 表示不限制',
        ],
        'security.rate_limit_window' => [
            '限流窗口', 'input-number', '60', '安全', 12,
            '单位秒，计数窗口长度',
        ],
        'security.password_max_length' => [
            '密码最大长度', 'input-number', '64', '安全', 7,
            '超长口令会放大 bcrypt 开销，构成低成本 DoS；0 表示不限制',
        ],
        'security.password_strength' => [
            '密码字符类别数', 'input-number', '2', '安全', 8,
            '需包含大写字母/小写字母/数字/符号中的几类；0 表示不要求。内置弱口令黑名单与「不得包含用户名等身份信息」始终生效',
        ],
        // 找回密码（P2-3）
        'security.reset_channel' => [
            '找回密码渠道', 'select', 'off', '安全', 13,
            '找回密码可用的验证码通道；off 表示关闭找回入口',
            '[{"label":"关闭","value":"off"},{"label":"邮箱","value":"email"},{"label":"短信","value":"sms"},{"label":"邮箱 + 短信","value":"both"}]',
        ],
        'security.reset_code_ttl' => [
            '验证码有效期', 'input-number', '300', '安全', 14,
            '单位秒，验证码在 Redis 中的存活时间，最小 60',
        ],
        'security.reset_send_interval' => [
            '发送间隔', 'input-number', '60', '安全', 15,
            '单位秒，同一账号两次发送验证码的最小间隔；0 表示不限制',
        ],
        'security.reset_max_attempts' => [
            '验证码尝试上限', 'input-number', '5', '安全', 16,
            '同一验证码最多校验几次，达到上限即作废，防暴力猜码',
        ],
        'security.reset_daily_limit' => [
            '每日发送上限', 'input-number', '10', '安全', 17,
            '同一账号每天最多发送几次验证码；0 表示不限制',
        ],
        'mail.enabled' => [
            '启用邮箱通道', 'switch', '0', '邮箱', 1,
            '开启后支持邮箱找回密码，还需在「安全」分组把找回渠道设为邮箱或邮箱+短信',
        ],
        'mail.host' => ['SMTP 服务器', 'input', '', '邮箱', 2, 'SMTP 主机名，如 smtp.example.com'],
        'mail.port' => ['SMTP 端口', 'input-number', '465', '邮箱', 3, 'SSL 常用 465，STARTTLS 常用 587'],
        'mail.username' => ['SMTP 账号', 'input', '', '邮箱', 4, '登录 SMTP 的用户名，留空表示不需要认证'],
        'mail.password' => ['SMTP 密码', 'password', '', '邮箱', 5, '登录 SMTP 的密码 / 授权码，加密存储'],
        'mail.encryption' => [
            '加密方式', 'select', 'ssl', '邮箱', 6,
            'ssl=直接加密连接，tls=STARTTLS 升级，none=明文（不推荐）',
            '[{"label":"SSL","value":"ssl"},{"label":"STARTTLS","value":"tls"},{"label":"不加密","value":"none"}]',
        ],
        'mail.from_address' => ['发件人邮箱', 'input', '', '邮箱', 7, '留空则使用 SMTP 账号'],
        'mail.from_name' => ['发件人名称', 'input', 'CCCMS', '邮箱', 8, '展示在收件人邮件客户端'],
        'sms.enabled' => [
            '启用短信通道', 'switch', '0', '短信', 1,
            '开启后支持短信找回密码，还需在「安全」分组把找回渠道设为短信或邮箱+短信',
        ],
        'sms.driver' => ['短信驱动', 'input', 'http', '短信', 2, '通用 HTTP 网关驱动标识（预留）'],
        'sms.gateway_url' => ['网关地址', 'input', '', '短信', 3, '短信服务商提供的发送接口地址'],
        'sms.method' => [
            '请求方法', 'select', 'POST', '短信', 4,
            'GET 时参数拼到 query，POST 时作为 JSON 请求体发送',
            '[{"label":"POST","value":"POST"},{"label":"GET","value":"GET"}]',
        ],
        'sms.params' => [
            '参数模板', 'textarea', '{"mobile":"{mobile}","code":"{code}","sign":"{sign}"}', '短信', 5,
            'JSON 对象；值里的 {mobile}/{code}/{sign} 会被替换为该次发送的实际值',
        ],
        'sms.headers' => [
            '请求头', 'textarea', '{}', '短信', 6,
            'JSON 对象，如 {"Authorization":"Bearer xxx"}；POST 未指定 Content-Type 时默认 application/json',
        ],
        'sms.sign_name' => ['短信签名', 'input', '', '短信', 7, '短信签名，替换参数模板里的 {sign}'],
    ];

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
            'status'   => "tinyint NOT NULL DEFAULT 1 COMMENT '1成功 0失败' AFTER `title`",
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
     * 多租户隔离列（P2-17）：12 张业务表补 `tenant_id`。
     *
     * 值为 0 表示**平台/默认租户**；老库升级时已有数据全部落到平台租户，
     * 因此升级后行为与升级前完全一致（超管看到的仍是全部数据）。
     *
     * 关联表（user_role / user_dept / user_post / dept_role / role_node /
     * notice_target / notice_read）刻意**不加**：它们通过主表（user / role /
     * dept / notice）间接隔离，主表已带 tenant_id，加一份冗余列只会带来
     * 「两处不一致」的隐患。sys_menu / sys_config / sys_log 是平台级资源。
     */
    private const TENANT_TABLES = [
        'user', 'dept', 'role', 'post', 'data_rule', 'data_scope_table',
        'notice', 'file', 'category', 'dict_type', 'dict_data', 'crontab',
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
        $defaults = 0;

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

        // 多租户：业务表补 tenant_id 列 + idx_tenant 索引（幂等）。
        // 已有数据自动落到 0（平台租户），升级后可见范围与升级前一致。
        foreach (self::TENANT_TABLES as $name) {
            $table = $prefix . $name;
            if (!$this->exists($table)) {
                $output->writeln("  <comment>跳过</comment> {$table}（表不存在）");
                continue;
            }
            $column = $this->columnMeta($table);
            if (!isset($column['tenant_id'])) {
                Db::execute(
                    "ALTER TABLE `{$table}` ADD COLUMN `tenant_id` bigint unsigned NOT NULL DEFAULT 0 "
                    . "COMMENT '租户ID(0=平台)' AFTER `id`"
                );
                $output->writeln("  <info>加列</info> {$table}.tenant_id");
                $cols++;
            }
            if (!isset($this->indexes($table)['idx_tenant'])) {
                Db::execute("ALTER TABLE `{$table}` ADD INDEX `idx_tenant` (`tenant_id`)");
                $output->writeln("  <info>加索引</info> {$table}.idx_tenant");
                $idxs++;
            }
        }

        // 列默认值纠偏：只改默认值，不动已有行的数据
        foreach (self::COLUMN_DEFAULTS as $name => $columns) {
            $table = $prefix . $name;
            if (!$this->exists($table)) {
                continue;
            }
            $meta = $this->columnMeta($table);
            foreach ($columns as $column => [$definition, $expected]) {
                if (!isset($meta[$column])) {
                    continue;
                }
                if ((string)($meta[$column]['Default'] ?? '') === $expected) {
                    continue;
                }
                Db::execute("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
                $output->writeln("  <info>默认值</info> {$table}.{$column} → {$expected}");
                $defaults++;
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
        // 登录日志与操作日志合并到同一张 sys_log，登录记录固定 path=/auth/login。
        if ($this->exists($prefix . 'login_log')) {
            try {
                Db::execute(
                    "INSERT INTO `{$prefix}log`
                     (user_id, username, status, message, method, path, title, ip, ua, create_time)
                     SELECT user_id, username, status, message, 'POST', '/auth/login', '登录', ip, ua, create_time
                     FROM `{$prefix}login_log`"
                );
                Db::execute("DROP TABLE `{$prefix}login_log`");
                $output->writeln('  <info>迁移登录日志</info> login_log → log（合并到 sys_log）');
            } catch (\Throwable $e) {
                // 迁移失败不阻断其余升级，但要显式报出来，避免静默丢数据
                $output->writeln('  <error>登录日志迁移失败</error>：' . $e->getMessage());
            }
        }

        // 移除日志类型列：登录与操作已可由 path 区分（登录固定 /auth/login，中间件不记该路径），
        // 单列 type 还带来一个只有两个取值的低选择性索引。删列时 MySQL 会一并带走 idx_type。
        $logTable = $prefix . 'log';
        if ($this->exists($logTable) && isset($this->columnMeta($logTable)['type'])) {
            Db::execute("ALTER TABLE `{$logTable}` DROP COLUMN `type`");
            $output->writeln("  <info>删列</info> {$logTable}.type（日志类型改用 path 区分）");
        }

        // 上传白名单移除可被浏览器内联执行的扩展名（svg / html / xml…）：
        // 本地驱动落盘 public/storage 且与后台同域直出，这类文件会执行其中脚本，
        // 构成存储型 XSS。代码侧已硬拒绝，这里同步清理存量配置，避免后台显示「仍允许」。
        $configTable = $prefix . 'config';
        if ($this->exists($configTable)) {
            $row = Db::query("SELECT `value` FROM `{$configTable}` WHERE `name` = 'upload.ext_allow' LIMIT 1");
            $value = strtolower((string)($row[0]['value'] ?? ''));
            if ($value !== '') {
                $items = array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== '');
                $hit   = array_intersect($items, StorageDriver::DANGEROUS_EXT);
                if ($hit) {
                    $clean = implode(',', array_values(array_diff($items, StorageDriver::DANGEROUS_EXT)));
                    Db::execute("UPDATE `{$configTable}` SET `value` = ? WHERE `name` = 'upload.ext_allow'", [$clean]);
                    $output->writeln('  <info>清理上传白名单</info> 移除 ' . implode('/', $hit) . '（可内联执行脚本，构成存储型 XSS）');
                }
            }

            // 令牌有效期纠偏：旧默认 7 天过长（令牌存 localStorage，TTL 就是 XSS
            // 一旦发生攻击者能用的窗口长度）。只改**仍是旧默认值**的行 —— 管理员
            // 显式调过的值不动，否则升级会覆盖线上有意为之的配置。
            // 缩短后由滑动续期保证活跃用户不掉线（见 CheckLogin::renew）。
            $ttlRow = Db::query("SELECT `value` FROM `{$configTable}` WHERE `name` = 'security.token_ttl' LIMIT 1");
            if ((string)($ttlRow[0]['value'] ?? '') === '604800') {
                Db::execute("UPDATE `{$configTable}` SET `value` = '7200' WHERE `name` = 'security.token_ttl'");
                $output->writeln('  <info>令牌有效期</info> 604800 → 7200 秒（配合滑动续期缩短 XSS 有效窗口）');
            }

            // 补插新增配置项：老库不会重跑 seed.sql，缺行则后台看不到该设置
            // （代码侧有默认值兜底，缺行不影响功能，只影响可调性）。
            // INSERT IGNORE 保证幂等，重复执行不会覆盖管理员改过的值。
            foreach (self::NEW_CONFIGS as $name => $cfg) {
                [$title, $type, $value, $group, $sort, $remark] = $cfg;
                $added = Db::execute(
                    "INSERT IGNORE INTO `{$configTable}`
                     (`name`, `title`, `type`, `value`, `options`, `group`, `sort`, `status`, `remark`, `create_time`, `update_time`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())",
                    [$name, $title, $type, $value, $cfg[6] ?? null, $group, $sort, $remark]
                );
                if ($added > 0) {
                    $output->writeln("  <info>新增配置</info> {$name}（{$title}）");
                }
            }
        }

        $output->writeln("<info>升级完成：建表 {$tables}，加列 {$cols}，加索引 {$idxs}，默认值 {$defaults}，时间戳 {$stamps}</info>");

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
