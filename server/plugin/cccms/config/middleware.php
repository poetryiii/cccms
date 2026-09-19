<?php

use plugin\cccms\app\middleware\CheckAuth;
use plugin\cccms\app\middleware\CheckLogin;
use plugin\cccms\app\middleware\Cors;
use plugin\cccms\app\middleware\Maintenance;
use plugin\cccms\app\middleware\OperationLog;
use plugin\cccms\app\middleware\RateLimit;
use plugin\cccms\app\middleware\ResponseEncode;
use plugin\cccms\app\middleware\Xss;

return [
    '' => [
        Cors::class,
        Xss::class,
        CheckLogin::class,
        // 限流必须在 CheckLogin 之后：计数键需要 $request->user（用户 + 路由）
        RateLimit::class,
        // 维护模式必须在 CheckLogin 之后：它依赖 $request->user 判断是否超管
        Maintenance::class,
        CheckAuth::class,
        ResponseEncode::class,
        OperationLog::class,
    ],
];
