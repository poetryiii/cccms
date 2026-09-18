<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\LoginLogLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

/** 登录日志（登录成功与失败都记录）。 */
class LoginLogController extends BaseController
{
    #[Permission(slug: 'cccms:login_log:index', title: '登录日志')]
    public function index(Request $request): Response
    {
        return $this->ok(LoginLogLogic::paginate($request->get()));
    }

    /** 导出 CSV（直接返回文件流，不走统一信封） */
    #[Permission(slug: 'cccms:login_log:export', title: '导出登录日志')]
    public function export(Request $request): Response
    {
        return LoginLogLogic::export($request->get());
    }

    #[Permission(slug: 'cccms:login_log:delete', title: '删除登录日志')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->ok(['deleted' => LoginLogLogic::delete((array)$request->post('ids', []))]);
    }

    #[Permission(slug: 'cccms:login_log:clear', title: '清空登录日志')]
    #[Restrict(methods: ['POST'])]
    public function clear(Request $request): Response
    {
        return $this->ok(['deleted' => LoginLogLogic::clear()]);
    }
}
