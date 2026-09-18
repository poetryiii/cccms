<?php

/**
 * CCCMS 插件级日志配置（读取用 config('plugin.cccms.log.*')）。
 *
 * 注意：与框架的 server/config/log.php（Monolog channel 定义）不是一回事，这里是业务参数。
 * 归档是破坏性操作（上传成功后删主库），所以给保守默认值；命令还会再强制天数下限 7。
 */
return [
    'archive' => [
        // 归档「创建时间早于 N 天」的记录
        'days'   => (int)(getenv('LOG_ARCHIVE_DAYS') ?: 30),
        // 每批处理条数，避免一次把全表拉进内存
        'batch'  => (int)(getenv('LOG_ARCHIVE_BATCH') ?: 1000),
        // 存储驱动：留空 = 跟随 upload.storage_driver 的当前驱动（local / oss / cos / qiniu）
        'driver' => getenv('LOG_ARCHIVE_DRIVER') ?: '',
        // 对象存储路径前缀
        'path'   => getenv('LOG_ARCHIVE_PATH') ?: 'log-archive',
    ],
];
