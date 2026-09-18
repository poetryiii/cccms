-- =====================================================================
-- CCCMS 初始化种子数据（在 schema.sql 之后执行）
-- 默认管理员：admin / admin123（首次登录后请务必修改）
--
-- 全部使用 INSERT IGNORE，可**重复执行**（靠唯一键去重），
-- 因此升级时可以直接再跑一遍，不会破坏已有数据。
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 超管角色（code 唯一，不可改不可删）
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_role` (`id`, `name`, `code`, `data_scope`, `parent_id`, `sort`, `status`, `remark`, `create_time`, `update_time`) VALUES
(1, '超级管理员', 'super_admin', 1, 0, 0, 1, '系统内置超管角色', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 默认管理员账号
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_user` (`id`, `username`, `password`, `nickname`, `status`, `remark`, `create_time`, `update_time`) VALUES
(1, 'admin', '$2y$10$R2WLwa0R5vUGIAOPgfDhsuDiKn418dMCwoGgilKdzo7mJbEvqv0VC', '超级管理员', 1, '系统内置管理员', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 管理员绑定超管角色
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_user_role` (`user_id`, `role_id`) VALUES (1, 1);

-- ---------------------------------------------------------------------
-- 数据权限受控表（默认登记基础系统里已接入数据权限的表）
--
-- 登记 = 可以在规则页给它配「自定义规则」；未登记的表仍受预设基线保护。
-- 新增业务插件接入数据权限后，在自己的 seed 里登记即可（有唯一键，可重复执行）。
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_data_scope_table` (`table_name`, `label`, `status`, `remark`, `create_time`, `update_time`) VALUES
('user',    '',       1, '已接入：用户管理', NOW(), NOW()),
('file',    '附件',    1, '模型声明参与：owner=create_by，本部门落到可见部门成员', NOW(), NOW()),
('log',     '操作日志', 1, '模型声明参与：owner=user_id，本部门落到可见部门成员', NOW(), NOW()),
('crontab', '定时任务', 1, '模型声明 no_baseline（无归属列），隔离依赖自定义规则', NOW(), NOW()),
('dept',    '部门',    1, '模型声明参与；「本部门及以下」档下部门页只见自己子树（见 docs/06）', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 内置定时任务：清理历史操作日志
--
-- 需要它「自动清理日志」配置（log.auto_clean / log.keep_days）才真正生效。
-- 用 INSERT ... SELECT ... WHERE NOT EXISTS 实现幂等（sys_crontab 没有可去重的唯一键，
-- 因此不能用 INSERT IGNORE）。
-- ---------------------------------------------------------------------
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '清理历史日志', '0 0 3 * * *', 'plugin\\cccms\\command\\task\\LogCleanTask', NULL, 1, '系统', 'skip', 600, 1, 300, '每天 03:00 清理 log.keep_days 之前的操作日志', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\LogCleanTask'
);

-- ---------------------------------------------------------------------
-- 内置定时任务：归档历史日志到对象存储
--
-- **默认停用（status=0）**：归档是破坏性操作（上传成功后删主库），
-- 需运维确认对象存储可用后再手动启用。参数取 plugin/cccms/config/log.php 的 archive 段。
-- 同样用 INSERT ... SELECT ... WHERE NOT EXISTS 保证幂等。
-- ---------------------------------------------------------------------
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '日志归档到对象存储', '0 0 4 * * *', 'plugin\\cccms\\command\\task\\LogArchiveTask', NULL, 0, '系统', 'skip', 3600, 1, 300, '每天 04:00 归档早于 log.archive.days 的日志到对象存储（默认停用，确认存储可用后启用）', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\LogArchiveTask'
);

-- ---------------------------------------------------------------------
-- 系统配置默认值
--
-- 这些配置项定义了系统的「可配置面」。
-- 当前状态：**已全部被后端业务代码消费**（品牌/界面/安全/上传/日志），
-- 保存后立即生效（SysConfig 走 Redis 缓存，保存时 flush），无需重启。
-- 已确认「不做密钥轮换」的 data_encrypt_key 不在此表，它在 config/auth.php。
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_config` (`name`, `title`, `type`, `value`, `options`, `group`, `sort`, `status`, `remark`, `create_time`, `update_time`) VALUES
-- 系统
('system.name',                 '系统名称',        'input',        'CCCMS',                        NULL, '系统', 1, 1, '显示在浏览器标题与登录页', NOW(), NOW()),
('system.logo',                 '系统 Logo',       'input',        '',                             NULL, '系统', 2, 1, '图片 URL，留空则使用内置 Logo（站点根 logo.png）', NOW(), NOW()),
('system.icp',                  '备案号',          'input',        '',                             NULL, '系统', 3, 1, '显示在前台页脚', NOW(), NOW()),
('system.copyright',            '版权信息',        'textarea',     '© 2026 CCCMS',                 NULL, '系统', 4, 1, '支持多行', NOW(), NOW()),
('system.maintenance',          '维护模式',        'switch',       '0',                            NULL, '系统', 5, 1, '开启后前台展示维护公告', NOW(), NOW()),
('system.maintenance_notice',   '维护公告',        'textarea',     '系统维护中，请稍后访问',        NULL, '系统', 6, 1, '维护模式下展示的内容', NOW(), NOW()),

-- 界面
('ui.theme_mode',               '默认主题模式',    'radio',        'light',                        '[{"label":"亮色","value":"light"},{"label":"暗色","value":"dark"},{"label":"跟随系统","value":"auto"}]', '界面', 1, 1, '新用户首次进入时的主题', NOW(), NOW()),
('ui.theme_primary',            '默认主色',        'input',        '#2b6cff',                      NULL, '界面', 2, 1, '十六进制色值，如 #2b6cff', NOW(), NOW()),
('ui.page_size',                '默认分页条数',    'input-number', '15',                           NULL, '界面', 3, 1, '列表页每页默认条数', NOW(), NOW()),
('ui.tags_view',                '显示多标签页',    'switch',       '1',                            NULL, '界面', 4, 1, '关闭后内容区只保留单页', NOW(), NOW()),
('ui.container_width',          '内容区最大宽度',  'input-number', '0',                            NULL, '界面', 5, 1, '单位 px，0 表示占满', NOW(), NOW()),

-- 安全
('security.login_captcha',      '登录验证码',      'switch',       '0',                            NULL, '安全', 1, 1, '开启后登录必须输入图形验证码', NOW(), NOW()),
('security.login_fail_limit',   '失败锁定次数',    'input-number', '5',                            NULL, '安全', 2, 1, '连续登录失败达到该次数后锁定账号', NOW(), NOW()),
('security.login_fail_window',  '锁定时长',        'input-number', '15',                           NULL, '安全', 3, 1, '单位分钟，超时后自动解锁', NOW(), NOW()),
('security.token_ttl',          '令牌有效期',      'input-number', '604800',                       NULL, '安全', 4, 1, '单位秒，默认 7 天', NOW(), NOW()),
('security.password_min_length','密码最小长度',    'input-number', '6',                            NULL, '安全', 5, 1, '新增用户与重置密码时校验', NOW(), NOW()),

-- 上传
('upload.max_size',             '单文件上限',      'input-number', '10',                           NULL, '上传', 1, 1, '单位 MB', NOW(), NOW()),
('upload.ext_allow',            '允许的扩展名',    'input',        'jpg,jpeg,png,gif,webp,bmp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,md,zip,rar,7z,mp3,mp4,webm', NULL, '上传', 2, 1, '英文逗号分隔，不要带点；留空则用 filesystem.php 的默认白名单', NOW(), NOW()),
('upload.image_ext',            '图片扩展名',      'input',        'jpg,jpeg,png,gif,webp',        NULL, '上传', 3, 1, '需要按图片处理的扩展名', NOW(), NOW()),
('upload.storage_driver',       '存储驱动',        'select',       'local',                        '[{"label":"本地","value":"local"},{"label":"阿里云 OSS","value":"oss"},{"label":"腾讯云 COS","value":"cos"},{"label":"七牛云","value":"qiniu"}]', '上传', 4, 1, '切换后需填写对应驱动配置', NOW(), NOW()),
('upload.url_prefix',           '访问前缀',        'input',        '/storage',                     NULL, '上传', 5, 1, '切独立域名 / CDN 时改这里', NOW(), NOW()),

-- 日志
('log.keep_days',               '日志保留天数',    'input-number', '30',                           NULL, '日志', 1, 1, '定时任务按此天数清理历史日志', NOW(), NOW()),
('log.auto_clean',              '自动清理日志',    'switch',       '1',                            NULL, '日志', 2, 1, '关闭后需手工清理', NOW(), NOW()),
('log.record_read',             '记录查询操作',    'switch',       '0',                            NULL, '日志', 3, 1, '开启后 GET 请求也会写入操作日志', NOW(), NOW()),
('log.slow_threshold',          '慢接口告警阈值',  'input-number', '3000',                         NULL, '日志', 4, 1, '单位毫秒；请求耗时超过该值时写慢接口告警日志(slow.log)，0 表示关闭', NOW(), NOW());
