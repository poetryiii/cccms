<?php

declare(strict_types=1);

/**
 * 内置配置项的展示名与分组名。
 *
 * key 约定：
 *   - `config_item.{name}`           配置项展示名，如 config_item.system.name
 *   - `config_item.group.{group}`    分组展示名，如 config_item.group.系统
 *   - `config_item.option.{value}`   单选项 / 下拉项标签，如 config_item.option.light
 *
 * 只有**系统内置**的项在这里登记；管理员新增的配置项没有 key，直接展示其录入值。
 */
return [
    // ---- 配置项展示名 ----
    'system.name'      => '系统名称',
    'system.logo'      => '系统 Logo',
    'system.icp'       => '备案号',
    'system.copyright' => '版权信息',
    'system.maintenance' => '维护模式',
    'system.maintenance_notice' => '维护公告',

    'ui.theme_mode'      => '默认主题模式',
    'ui.theme_primary'   => '默认主色',
    'ui.page_size'       => '默认分页条数',
    'ui.tags_view'       => '显示多标签页',
    'ui.container_width' => '内容区最大宽度',

    'security.login_captcha'       => '登录验证码',
    'security.login_fail_limit'    => '失败锁定次数',
    'security.login_fail_ip_limit' => '同 IP 失败上限',
    'security.login_fail_window'   => '锁定时长',
    'security.token_ttl'           => '令牌有效期',
    'security.password_min_length' => '密码最小长度',
    'security.password_max_length' => '密码最大长度',
    'security.password_strength'   => '密码字符类别数',
    'security.rate_limit_enable'   => '接口限流开关',
    'security.rate_limit_limit'    => '普通接口次数',
    'security.rate_limit_heavy_limit' => '重接口次数',
    'security.rate_limit_window'   => '限流窗口',
    'security.reset_channel'       => '找回密码渠道',
    'security.reset_code_ttl'      => '验证码有效期',
    'security.reset_send_interval' => '发送间隔',
    'security.reset_max_attempts'  => '验证码尝试上限',
    'security.reset_daily_limit'   => '每日发送上限',

    'mail.enabled'      => '启用邮箱通道',
    'mail.host'         => 'SMTP 服务器',
    'mail.port'         => 'SMTP 端口',
    'mail.username'     => 'SMTP 账号',
    'mail.password'     => 'SMTP 密码',
    'mail.encryption'   => '加密方式',
    'mail.from_address' => '发件人邮箱',
    'mail.from_name'    => '发件人名称',

    'sms.enabled'     => '启用短信通道',
    'sms.driver'      => '短信驱动',
    'sms.gateway_url' => '网关地址',
    'sms.method'      => '请求方法',
    'sms.params'      => '参数模板',
    'sms.headers'     => '请求头',
    'sms.sign_name'   => '短信签名',

    'upload.max_size'       => '单文件上限',
    'upload.ext_allow'      => '允许的扩展名',
    'upload.image_ext'      => '图片扩展名',
    'upload.storage_driver' => '存储驱动',
    'upload.url_prefix'     => '访问前缀',

    'upload.oss_access_key_id'     => 'AccessKey ID',
    'upload.oss_access_key_secret' => 'AccessKey Secret',
    'upload.oss_bucket'            => 'Bucket',
    'upload.oss_endpoint'          => 'Endpoint',
    'upload.oss_domain'            => '自定义域名',

    'upload.cos_secret_id'  => 'SecretId',
    'upload.cos_secret_key' => 'SecretKey',
    'upload.cos_bucket'     => 'Bucket',
    'upload.cos_region'     => 'Region',
    'upload.cos_domain'     => '自定义域名',

    'upload.qiniu_access_key' => 'AccessKey',
    'upload.qiniu_secret_key' => 'SecretKey',
    'upload.qiniu_bucket'     => 'Bucket',
    'upload.qiniu_domain'     => '访问域名',

    'log.keep_days'      => '日志保留天数',
    'log.auto_clean'     => '自动清理日志',
    'log.record_read'    => '记录查询操作',
    'log.slow_threshold' => '慢接口告警阈值',

    // ---- 分组展示名 ----
    'group.系统' => '系统',
    'group.界面' => '界面',
    'group.安全' => '安全',
    'group.上传' => '上传',
    'group.日志' => '日志',
    'group.邮箱' => '邮箱',
    'group.短信' => '短信',

    // ---- 选项标签 ----
    'option.light'  => '亮色',
    'option.dark'   => '暗色',
    'option.auto'   => '跟随系统',
    'option.local'  => '本地',
    'option.oss'    => '阿里云 OSS',
    'option.cos'    => '腾讯云 COS',
    'option.qiniu'  => '七牛云',
    'option.off'    => '关闭',
    'option.email'  => '邮箱',
    'option.sms'    => '短信',
    'option.both'   => '邮箱 + 短信',
    'option.ssl'    => 'SSL',
    'option.tls'    => 'STARTTLS',
    'option.none'   => '不加密',
];