<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\LogLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class LogController extends BaseController
{
    #[Permission(slug: 'cccms:log:index', title: '操作日志')]
    public function index(Request $request): Response
    {
        return $this->ok(LogLogic::paginate($request->get()));
    }

    /** 导出 CSV（按当前筛选与数据范围） */
    #[Permission(slug: 'cccms:log:export', title: '导出操作日志')]
    public function export(Request $request): Response
    {
        return LogLogic::export($request->get());
    }

    /** 按 trace_id 聚合一次请求的全部日志（链路视图） */
    #[Permission(slug: 'cccms:log:trace', title: '查看链路日志')]
    public function trace(Request $request): Response
    {
        return $this->ok(LogLogic::trace((string)$request->get('trace_id', '')));
    }

    #[Permission(slug: 'cccms:log:delete', title: '删除日志')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $ids = $request->post('ids', []);
        LogLogic::delete(is_array($ids) ? $ids : []);
        return $this->ok(null, I18n::t('common.deleted'));
    }
}
