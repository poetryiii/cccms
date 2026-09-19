<?php

declare(strict_types=1);

/**
 * Display names for built-in configuration items and groups.
 *
 * Key convention:
 *   - `config_item.{name}`          item label, e.g. config_item.system.name
 *   - `config_item.group.{group}`   group label, e.g. config_item.group.系统
 *   - `config_item.option.{value}`  radio / select option label, e.g. config_item.option.light
 *
 * Only **built-in** items are registered here; administrator-created items have no key
 * and are displayed with the raw value they entered.
 *
 * The `group.*` keys intentionally keep the Chinese group value (it is the filter value
 * passed back via `?group=`), only the translated text differs.
 */
return [
    // ---- item labels ----
    'system.name'      => 'System name',
    'system.logo'      => 'System logo',
    'system.icp'       => 'ICP filing number',
    'system.copyright' => 'Copyright',
    'system.maintenance' => 'Maintenance mode',
    'system.maintenance_notice' => 'Maintenance notice',

    'ui.theme_mode'      => 'Default theme mode',
    'ui.theme_primary'   => 'Default primary color',
    'ui.page_size'       => 'Default page size',
    'ui.tags_view'       => 'Show tab bar',
    'ui.container_width' => 'Max content width',

    'security.login_captcha'       => 'Login captcha',
    'security.login_fail_limit'    => 'Failed attempt limit',
    'security.login_fail_ip_limit' => 'Failed attempt limit per IP',
    'security.login_fail_window'   => 'Lockout duration',
    'security.token_ttl'           => 'Token lifetime',
    'security.password_min_length' => 'Minimum password length',
    'security.password_max_length' => 'Maximum password length',
    'security.password_strength'   => 'Required character classes',
    'security.rate_limit_enable'   => 'Rate limit',
    'security.rate_limit_limit'    => 'Normal endpoint quota',
    'security.rate_limit_heavy_limit' => 'Heavy endpoint quota',
    'security.rate_limit_window'   => 'Rate limit window',
    'security.reset_channel'       => 'Password reset channel',
    'security.reset_code_ttl'      => 'Code lifetime',
    'security.reset_send_interval' => 'Sending interval',
    'security.reset_max_attempts'  => 'Max code attempts',
    'security.reset_daily_limit'   => 'Daily sending limit',

    'mail.enabled'      => 'Enable email channel',
    'mail.host'         => 'SMTP host',
    'mail.port'         => 'SMTP port',
    'mail.username'     => 'SMTP username',
    'mail.password'     => 'SMTP password',
    'mail.encryption'   => 'Encryption',
    'mail.from_address' => 'From address',
    'mail.from_name'    => 'From name',

    'sms.enabled'     => 'Enable SMS channel',
    'sms.driver'      => 'SMS driver',
    'sms.gateway_url' => 'Gateway URL',
    'sms.method'      => 'Request method',
    'sms.params'      => 'Parameter template',
    'sms.headers'     => 'Request headers',
    'sms.sign_name'   => 'SMS signature',

    'upload.max_size'       => 'Max file size',
    'upload.ext_allow'      => 'Allowed extensions',
    'upload.image_ext'      => 'Image extensions',
    'upload.storage_driver' => 'Storage driver',
    'upload.url_prefix'     => 'URL prefix',

    'upload.oss_access_key_id'     => 'AccessKey ID',
    'upload.oss_access_key_secret' => 'AccessKey Secret',
    'upload.oss_bucket'            => 'Bucket',
    'upload.oss_endpoint'          => 'Endpoint',
    'upload.oss_domain'            => 'Custom domain',

    'upload.cos_secret_id'  => 'SecretId',
    'upload.cos_secret_key' => 'SecretKey',
    'upload.cos_bucket'     => 'Bucket',
    'upload.cos_region'     => 'Region',
    'upload.cos_domain'     => 'Custom domain',

    'upload.qiniu_access_key' => 'AccessKey',
    'upload.qiniu_secret_key' => 'SecretKey',
    'upload.qiniu_bucket'     => 'Bucket',
    'upload.qiniu_domain'     => 'Access domain',

    'log.keep_days'      => 'Log retention days',
    'log.auto_clean'     => 'Auto clean logs',
    'log.record_read'    => 'Log read operations',
    'log.slow_threshold' => 'Slow request threshold',

    // ---- group labels ----
    'group.系统' => 'System',
    'group.界面' => 'Interface',
    'group.安全' => 'Security',
    'group.上传' => 'Upload',
    'group.日志' => 'Logs',
    'group.邮箱' => 'Email',
    'group.短信' => 'SMS',

    // ---- option labels ----
    'option.light'  => 'Light',
    'option.dark'   => 'Dark',
    'option.auto'   => 'System',
    'option.local'  => 'Local',
    'option.oss'    => 'Alibaba Cloud OSS',
    'option.cos'    => 'Tencent Cloud COS',
    'option.qiniu'  => 'Qiniu Kodo',
    'option.off'    => 'Off',
    'option.email'  => 'Email',
    'option.sms'    => 'SMS',
    'option.both'   => 'Email + SMS',
    'option.ssl'    => 'SSL',
    'option.tls'    => 'STARTTLS',
    'option.none'   => 'None',
];