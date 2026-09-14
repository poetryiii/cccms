-- =====================================================================
-- CCCMS 核心表结构（MySQL 8 / InnoDB / utf8mb4）
-- 时间戳：create_time / update_time 由数据库默认值自动写入
--         （DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP），
--         业务代码用查询构造器也不会漏写。
-- 软删除：业务主数据带 `delete_time`（NULL = 未删除），见 support/SoftDelete 与「回收站」；
--         关联表 / 日志 / 配置不做软删除。
--         带唯一键的表（user/role/post/dict_type）软删后唯一值仍被占用，
--         要复用该值需先在回收站里「彻底删除」。
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 用户
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user` (
  `id`           bigint unsigned NOT NULL AUTO_INCREMENT,
  `username`     varchar(64)  NOT NULL COMMENT '登录账号',
  `password`     varchar(255) NOT NULL DEFAULT '' COMMENT '密码(bcrypt)',
  `nickname`     varchar(64)  NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar`       varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `email`        varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `phone`        varchar(32)  NOT NULL DEFAULT '' COMMENT '手机号',
  `status`       tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `remark`       varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `login_time`   datetime     DEFAULT NULL COMMENT '最后登录时间',
  `login_ip`     varchar(64)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `create_time`  datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time`  datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ---------------------------------------------------------------------
-- 角色（含继承与数据范围）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_role` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL COMMENT '角色名',
  `code`        varchar(64)  NOT NULL COMMENT '角色标识(唯一)',
  `data_scope`  tinyint      NOT NULL DEFAULT 1 COMMENT '数据范围 1全部 2本部门及以下 3本部门 4仅本人 5自定义',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '父角色(继承)，0=顶级',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表';

-- ---------------------------------------------------------------------
-- 用户-角色
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_role` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联';

-- ---------------------------------------------------------------------
-- 菜单 + 权限节点树（node = slug，全局唯一）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_menu` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '父节点',
  `type`        tinyint      NOT NULL DEFAULT 1 COMMENT '1目录 2菜单 3按钮',
  `title`       varchar(64)  NOT NULL COMMENT '名称',
  `path`        varchar(128) NOT NULL DEFAULT '' COMMENT '路由地址(目录/菜单)',
  `component`   varchar(128) NOT NULL DEFAULT '' COMMENT '前端组件路径(菜单)',
  `icon`        varchar(64)  NOT NULL DEFAULT '' COMMENT '图标',
  `sort`        int          NOT NULL DEFAULT 0,
  `node`        varchar(128) NOT NULL DEFAULT '' COMMENT '权限节点标识 slug(按钮必填)',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1显示 0隐藏',
  `keep_alive`  tinyint      NOT NULL DEFAULT 0 COMMENT '是否缓存 1是 0否',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_node` (`node`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='菜单权限节点表';

-- ---------------------------------------------------------------------
-- 角色-节点授权
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_role_node` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `node`    varchar(128) NOT NULL COMMENT 'slug',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_node` (`role_id`, `node`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色节点授权';

-- ---------------------------------------------------------------------
-- 部门（无限级）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dept` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0,
  `name`        varchar(64)  NOT NULL COMMENT '部门名',
  `leader`      varchar(64)  NOT NULL DEFAULT '' COMMENT '负责人',
  `phone`       varchar(32)  NOT NULL DEFAULT '',
  `email`       varchar(128) NOT NULL DEFAULT '',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部门表';

-- ---------------------------------------------------------------------
-- 用户-部门（多对多）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_dept` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dept_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_dept` (`user_id`, `dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户部门关联';

-- ---------------------------------------------------------------------
-- 部门-角色
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dept_role` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `dept_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dept_role` (`dept_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部门角色关联';

-- ---------------------------------------------------------------------
-- 岗位
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_post` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `code`        varchar(64)  NOT NULL COMMENT '岗位编码',
  `name`        varchar(64)  NOT NULL COMMENT '岗位名',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='岗位表';

-- ---------------------------------------------------------------------
-- 用户-岗位
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_post` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `post_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_post` (`user_id`, `post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户岗位关联';

-- ---------------------------------------------------------------------
-- 数据权限规则（行级 + 字段级）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_data_rule` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL DEFAULT '' COMMENT '规则名',
  `user_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定用户 0=不限',
  `post_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定岗位 0=不限',
  `dept_ids`    json         DEFAULT NULL COMMENT '绑定部门(多选,跨部门)',
  `role_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定角色 0=不限',
  `table_name`  varchar(64)  NOT NULL DEFAULT '' COMMENT '目标表(不含前缀)，空=不限表',
  `field`       varchar(64)  NOT NULL DEFAULT '' COMMENT '字段名',
  `action`      varchar(16)  NOT NULL DEFAULT 'row' COMMENT 'row/hidden/readonly/mask/encrypt',
  `operator`    varchar(16)  NOT NULL DEFAULT '=' COMMENT '行级操作符 = != in like > >= < <= between',
  `value`       varchar(255) NOT NULL DEFAULT '' COMMENT '行级取值(逗号分隔；动态变量如 {dept.subtree})',
  `value_type`  varchar(16)  NOT NULL DEFAULT 'static' COMMENT '取值类型 static静态 dynamic动态变量',
  `sort`        int          NOT NULL DEFAULT 0,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限规则';

-- ---------------------------------------------------------------------
-- 数据权限受控表（哪些表可以配数据权限；未列入的表在规则页被隐藏）
-- 只有业务 Logic 真正调用了 DataScope 的表才有意义，因此这里是一份「登记表」
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_data_scope_table` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `table_name`  varchar(64)  NOT NULL COMMENT '表名(不含前缀)',
  `label`       varchar(64)  NOT NULL DEFAULT '' COMMENT '语义名，留空则取表注释',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1受控 0停用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限受控表';

-- ---------------------------------------------------------------------
-- 通用分类（多模块共用，按 module 区分；可层级）
-- 目前：module=dict 字典类型分类、module=file 附件分类
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_category` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `module`      varchar(32)  NOT NULL DEFAULT '' COMMENT '所属模块 dict/file',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '上级分类，0=顶级',
  `name`        varchar(64)  NOT NULL COMMENT '分类名',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通用分类';

-- ---------------------------------------------------------------------
-- 数据字典
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dict_type` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '分类ID(0=未分类)',
  `name`        varchar(64) NOT NULL COMMENT '字典名',
  `type`        varchar(64) NOT NULL COMMENT '字典类型标识',
  `status`      tinyint     NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime    NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime    DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`type`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字典类型';

CREATE TABLE IF NOT EXISTS `sys_dict_data` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `type_id`     bigint unsigned NOT NULL COMMENT '字典类型ID',
  `label`       varchar(64)  NOT NULL COMMENT '显示名',
  `value`       varchar(128) NOT NULL COMMENT '值',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字典数据';

-- ---------------------------------------------------------------------
-- 系统配置
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_config` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL COMMENT '配置键',
  `title`       varchar(64)  NOT NULL DEFAULT '' COMMENT '配置名',
  `type`        varchar(32)  NOT NULL DEFAULT 'input' COMMENT 'input/switch/select/input-number/textarea/radio',
  `value`       text         COMMENT '配置值',
  `options`     json         DEFAULT NULL COMMENT '可选值(select/radio)',
  `group`       varchar(32)  NOT NULL DEFAULT '' COMMENT '分组',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置';

-- ---------------------------------------------------------------------
-- 操作日志
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     bigint unsigned NOT NULL DEFAULT 0,
  `username`    varchar(64)  NOT NULL DEFAULT '',
  `method`      varchar(16)  NOT NULL DEFAULT '',
  `path`        varchar(255) NOT NULL DEFAULT '',
  `node`        varchar(128) NOT NULL DEFAULT '' COMMENT '权限节点 slug(路径语义化标识)',
  `title`       varchar(128) NOT NULL DEFAULT '' COMMENT '语义化操作名(取自权限注解)',
  `ip`          varchar(64)  NOT NULL DEFAULT '',
  `ua`          varchar(255) NOT NULL DEFAULT '',
  `params`      text         COMMENT '请求参数(query + body，已脱敏)',
  `result`      text         COMMENT '执行结果',
  `status_code` int          NOT NULL DEFAULT 200,
  `cost`        int          NOT NULL DEFAULT 0 COMMENT '耗时(ms)',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_node` (`node`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志';

-- ---------------------------------------------------------------------
-- 附件
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_file` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`          varchar(255) NOT NULL DEFAULT '' COMMENT '存储文件名',
  `original_name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `path`          varchar(255) NOT NULL DEFAULT '' COMMENT '存储路径',
  `url`           varchar(255) NOT NULL DEFAULT '' COMMENT '访问URL',
  `category_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '分类ID(0=未分类)',
  `ext`           varchar(16)  NOT NULL DEFAULT '',
  `mime`          varchar(64)  NOT NULL DEFAULT '',
  `size`          bigint       NOT NULL DEFAULT 0 COMMENT '字节',
  `driver`        varchar(16)  NOT NULL DEFAULT 'local',
  `hash`          varchar(64)  NOT NULL DEFAULT '' COMMENT 'sha1',
  `create_by`     bigint unsigned NOT NULL DEFAULT 0,
  `create_time`   datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `delete_time`   datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_hash` (`hash`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='附件表';

-- ---------------------------------------------------------------------
-- 定时任务（二期）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_crontab` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`          varchar(64)  NOT NULL COMMENT '任务名称',
  `expression`    varchar(64)  NOT NULL COMMENT 'cron 表达式（秒 分 时 日 月 周，六段）',
  `target`        varchar(255) NOT NULL COMMENT '执行目标：实现 CrontabTask 的类名',
  `params`        json         DEFAULT NULL COMMENT '参数数组',
  `status`        tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `remark`        varchar(255) NOT NULL DEFAULT '',
  `last_run_time` datetime     DEFAULT NULL,
  `next_run_time` datetime     DEFAULT NULL,
  `create_time`   datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time`   datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务';

CREATE TABLE IF NOT EXISTS `sys_crontab_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id`  bigint unsigned NOT NULL DEFAULT 0,
  `name`        varchar(64)  NOT NULL DEFAULT '',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `output`      text         COMMENT '输出 / 异常信息',
  `cost`        int          NOT NULL DEFAULT 0 COMMENT '耗时(ms)',
  `run_time`    datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crontab` (`crontab_id`),
  KEY `idx_run_time` (`run_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务执行日志';

SET FOREIGN_KEY_CHECKS = 1;
