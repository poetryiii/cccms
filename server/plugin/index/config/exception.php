<?php

use support\exception\Handler;

/*
 * 公开前台插件（plugin/index）保持 webman 默认异常渲染。
 *
 * 根 config/exception.php 的 `@` 兜底是给「后台业务插件」用的（统一渲染 Result 结构）；
 * 公开站点不需要 JSON 化的错误体，故这里显式声明沿用默认处理器，避免被 `@` 兜底影响。
 */
return [
    '' => Handler::class,
];
