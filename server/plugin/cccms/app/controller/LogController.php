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

    /** 导出 CSV（按当前筛选与数据范围；超阈值转异步任务） */
    #[Permission(slug: 'cccms:log:export', title: '导出操作日志')]
    public function export(Request $request): Response
    {
        $result = LogLogic::export($request->get(), $request->user->id);

        // 小数据量同步返回文件流；超阈值返回 {async, task_id} 交给前端轮询任务中心
        return $result instanceof Response ? $result : $this->ok($result);
    }

    /** 按 trace_id 聚合一次请求的全部日志（链路视图） */
    #[Permission(slug: 'cccms:log:trace', title: '查看链路日志')]
    public function trace(Request $request): Response
    {
        return $this->ok(LogLogic::trace((string)$request->get('trace_id', '')));
    }

    /** 登录安全分析：失败趋势 / TOP 用户名 / TOP IP / 异地登录（受数据范围约束） */
    #[Permission(slug: 'cccms:log:analysis', title: '查看登录分析')]
    public function loginAnalysis(Request $request): Response
    {
        return $this->ok(LogLogic::loginAnalysis($request->get()));
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
