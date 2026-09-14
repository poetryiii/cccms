<?php

use plugin\cccms\process\Crontab;

return [
    // 定时任务调度进程（仅 Linux/macOS 生效）
    'crontab' => [
        'handler'    => Crontab::class,
        'count'      => 1,
        'reloadable' => false,
    ],
];
