<?php

// think-orm 连接配置。可通过环境变量覆盖（部署时注入）。
return [
    'default'     => 'mysql',
    'connections' => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => getenv('DB_HOST') ?: '127.0.0.1',
            'database' => getenv('DB_DATABASE') ?: 'cccms',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '123456',
            'hostport' => getenv('DB_PORT') ?: '3306',
            'charset'  => 'utf8mb4',
            'prefix'   => 'sys_',
            'debug'    => (bool)(getenv('DB_DEBUG') ?: false),
            'deploy'   => 0,
            'rw_separate' => false,
            'fields_strict' => true,
            'auto_timestamp' => 'datetime',
        ],
    ],
];
