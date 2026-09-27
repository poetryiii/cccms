<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\ExportTask;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 导出任务中心（P2-7）。
 *
 * 两个接口都是 `#[NoAuth]`（登录即可）：任务与下载都按 `user_id` 归属校验，
 * 这里只暴露「本人的导出任务」，不需要额外的按钮权限节点；
 * 「能不能发起导出」由各导出接口自己的权限节点把关（如 `cccms:log:export`）。
 */
class ExportController extends BaseController
{
    /** 我的导出任务列表（最新在前） */
    #[NoAuth(title: '导出任务列表')]
    public function list(Request $request): Response
    {
        return $this->ok(ExportTask::list($request->user->id));
    }

    /** 下载导出归档文件（校验归属 + 状态 + 文件仍存在） */
    #[NoAuth(title: '下载导出文件')]
    public function download(Request $request): Response
    {
        return ExportTask::download((int)$request->get('id', 0), $request->user->id);
    }
}
