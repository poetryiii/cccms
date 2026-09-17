<?php

use plugin\cccms\support\Result;
use Webman\Http\Request;
use Webman\Route;

/*
 * 404 兜底：统一 JSON 结构。
 *
 * 这里的「自曝路径」是刻意为之：未匹配路由时把 **请求方法 + 路径** 回显出来，
 * 并落一行日志到 `runtime/logs/route-404.log`，便于排查「前端/三方调用了不存在的接口」
 * （此前统一返回「接口不存在」，只能靠猜是哪一条）。
 * 日志写入失败不影响响应，故用 @ 抑制。
 */
Route::fallback(function (Request $request) {
    @file_put_contents(
        runtime_path() . '/logs/route-404.log',
        sprintf(
            "%s %s %s referer=%s ua=%s\n",
            date('Y-m-d H:i:s'),
            $request->method(),
            $request->uri(),
            (string)$request->header('referer', '-'),
            (string)$request->header('user-agent', '-')
        ),
        FILE_APPEND
    );

    return Result::fail('接口不存在：' . $request->method() . ' ' . $request->path(), 404);
});
