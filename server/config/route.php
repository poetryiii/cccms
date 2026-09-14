<?php

use plugin\cccms\support\Result;
use Webman\Http\Request;
use Webman\Route;

// 404 兜底：统一 JSON 结构
Route::fallback(function (Request $request) {
    return Result::fail('接口不存在', 404);
});
