<?php

declare(strict_types=1);

namespace plugin\index\app\controller;

use plugin\cccms\basic\BaseController;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 前台（index 应用）公开接口。
 *
 * 本插件的路由不带鉴权中间件，所有方法默认公开；
 * 若某个接口需要登录/权限，请自行在 plugin/index/config/middleware.php 挂载对应中间件。
 */
class HomeController extends BaseController
{
    /** 健康检查 */
    public function ping(Request $request): Response
    {
        return $this->ok(['app' => 'index', 'time' => date('Y-m-d H:i:s')]);
    }

    /** 首页数据 */
    public function home(Request $request): Response
    {
        return $this->ok([
            'title'  => 'CCCMS 前台',
            'notice' => '前台插件骨架，按需在此扩展业务',
        ]);
    }
}
