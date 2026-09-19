<?php

return [
    // 当前驱动：local | oss | cos | qiniu
    'driver'     => getenv('STORAGE_DRIVER') ?: 'local',

    // 本地驱动：URL 前缀（同域 /storage）与落盘目录 public/storage
    'url_prefix' => '/storage',

    // 上传白名单与大小上限（所有驱动共用）
    //
    // ⚠️ 安全约束：**不要加入 svg / html / xml**。本地驱动落盘 `public/storage` 且与后台
    // 同域直出，这类文件被浏览器按 Content-Type 内联渲染时会执行其中的脚本，
    // 形成存储型 XSS（可读取 localStorage 中的 token）。如需 SVG，请先二次渲染为 PNG。
    'allow_ext'  => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'md', 'zip', 'rar', '7z',
        'mp3', 'mp4', 'webm',
    ],
    'max_size'   => 10 * 1024 * 1024,

    // ---- 阿里云 OSS（composer require aliyuncs/oss-sdk-php）----
    'oss' => [
        'access_key_id'     => getenv('OSS_ACCESS_KEY_ID') ?: '',
        'access_key_secret' => getenv('OSS_ACCESS_KEY_SECRET') ?: '',
        'bucket'            => getenv('OSS_BUCKET') ?: '',
        'endpoint'          => getenv('OSS_ENDPOINT') ?: '',
        // 自定义域名 / CDN，留空则用 endpoint 拼装
        'domain'            => getenv('OSS_DOMAIN') ?: '',
    ],

    // ---- 腾讯云 COS（composer require qcloud/cos-sdk-v5）----
    'cos' => [
        'secret_id'  => getenv('COS_SECRET_ID') ?: '',
        'secret_key' => getenv('COS_SECRET_KEY') ?: '',
        'bucket'     => getenv('COS_BUCKET') ?: '',
        'region'     => getenv('COS_REGION') ?: '',
        'domain'     => getenv('COS_DOMAIN') ?: '',
    ],

    // ---- 七牛云 Kodo（composer require qiniu/php-sdk）----
    'qiniu' => [
        'access_key' => getenv('QINIU_ACCESS_KEY') ?: '',
        'secret_key' => getenv('QINIU_SECRET_KEY') ?: '',
        'bucket'     => getenv('QINIU_BUCKET') ?: '',
        'domain'     => getenv('QINIU_DOMAIN') ?: '',
    ],
];
