<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use plugin\cccms\support\ExceptionHandler as CccmsExceptionHandler;
use support\exception\Handler as DefaultHandler;

return [
    '' => DefaultHandler::class,

    /*
     * 全局兜底异常处理器（webman 约定的 `@` 键）：插件未自带 config/exception.php 时使用它。
     *
     * ⚠️ 不配置会踩坑：webman 按「控制器所属插件」解析异常处理器
     * （读 `config('plugin.{插件}.exception')`，见 Webman\App::exceptionResponse）。
     * 业务插件（如 kuaishou）没配就会回落到框架默认的 Webman\Exception\ExceptionHandler，
     * 于是**所有 ApiException 业务提示都被渲染成「Server internal error」**，
     * 前端拿不到任何可读错误（排查时只能靠猜）。
     */
    '@' => CccmsExceptionHandler::class,
];
