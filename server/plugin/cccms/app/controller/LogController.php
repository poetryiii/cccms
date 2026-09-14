<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\LogLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class LogController extends BaseController
{
    #[Permission(slug: 'cccms:log:index', title: '操作日志')]
    public function index(Request $request): Response
    {
        return $this->ok(LogLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:log:delete', title: '删除日志')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $ids = $request->post('ids', []);
        LogLogic::delete(is_array($ids) ? $ids : []);
        return $this->ok(null, '删除成功');
    }
}
