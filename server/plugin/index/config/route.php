<?php

use plugin\index\app\controller\HomeController;
use Webman\Route;

Route::disableDefaultRoute();

// ---- 前台公开接口 ----
Route::get('/site/ping', [HomeController::class, 'ping']);
Route::get('/site/home', [HomeController::class, 'home']);
