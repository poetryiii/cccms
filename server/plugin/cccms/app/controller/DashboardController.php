<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\DashboardLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use Webman\Http\Request;
use Webman\Http\Response;

class DashboardController extends BaseController
{
    /**
     * 工作台统计数据。
     *
     * 用 #[NoAuth] 而非 #[Permission]：工作台是登录后的默认落地页，
     * 若要求具体权限节点，普通角色会一进来就看到 403。
     * 这里只返回聚合计数，不暴露任何业务明细，故对已登录用户开放是安全的。
     *
     * 新增模块的计数在 Logic 里按权限节点逐项下发（无权限的键不返回），
     * 因此「登录即可访问」不会顺带把各管理模块的数据量也带出去。
     */
    #[NoAuth]
    public function stats(Request $request): Response
    {
        return $this->ok(DashboardLogic::stats($request->user));
    }
}
