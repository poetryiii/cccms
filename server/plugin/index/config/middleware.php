<?php

use plugin\cccms\app\middleware\Cors;

/**
 * 前台（index 应用）中间件。
 *
 * 关键点：中间件**按插件作用域**生效（Middleware::getMiddleware 用 $plugin 取 instances[$plugin]['']），
 * 因此 cccms 的 CheckLogin / CheckAuth 不会作用于本插件的路由 —— 前台天然公开，
 * 无需在每个方法上写 #[NoLogin]（写了也不会被执行）。
 */
return [
    '' => [
        Cors::class,
    ],
];
