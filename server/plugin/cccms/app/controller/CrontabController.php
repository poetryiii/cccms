<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\CrontabLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class CrontabController extends BaseController
{
    #[Permission(slug: 'cccms:crontab:index', title: '定时任务列表')]
    public function index(Request $request): Response
    {
        return $this->ok(CrontabLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:crontab:targets', title: '可调度任务类')]
    public function targets(Request $request): Response
    {
        return $this->ok(CrontabLogic::targets());
    }

    #[Permission(slug: 'cccms:crontab:save', title: '新增定时任务')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => CrontabLogic::create($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:crontab:update', title: '更新定时任务')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        CrontabLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:crontab:delete', title: '删除定时任务')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        CrontabLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }

    #[Permission(slug: 'cccms:crontab:run', title: '立即执行')]
    #[Restrict(methods: ['POST'])]
    public function run(Request $request): Response
    {
        $result = CrontabLogic::runOnce((int)$request->input('id', 0));
        return $this->ok($result, $result['status'] === 1 ? '执行成功' : '执行失败');
    }

    #[Permission(slug: 'cccms:crontab:logs', title: '任务执行日志')]
    public function logs(Request $request): Response
    {
        return $this->ok(CrontabLogic::logs($request->get()));
    }
}
