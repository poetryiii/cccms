<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\UserLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class UserController extends BaseController
{
    #[Permission(slug: 'cccms:user:index', title: '用户列表')]
    public function index(Request $request): Response
    {
        return $this->ok(UserLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:user:read', title: '用户详情')]
    public function read(Request $request): Response
    {
        return $this->ok(UserLogic::read((int)$request->input('id', 0)));
    }

    #[Permission(slug: 'cccms:user:save', title: '新增用户')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => UserLogic::create($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:user:update', title: '更新用户')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        UserLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:user:delete', title: '删除用户')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        UserLogic::delete((int)$request->input('id', 0), $request->user);
        return $this->ok(null, '删除成功');
    }

    #[Permission(slug: 'cccms:user:reset_password', title: '重置密码')]
    #[Restrict(methods: ['POST'])]
    public function resetPassword(Request $request): Response
    {
        UserLogic::resetPassword(
            (int)$request->input('id', 0),
            (string)$request->post('password', '')
        );
        return $this->ok(null, '重置成功');
    }

    /** 导出 CSV（按当前筛选与数据范围，直接返回文件流而非统一信封） */
    #[Permission(slug: 'cccms:user:export', title: '导出用户')]
    public function export(Request $request): Response
    {
        return UserLogic::export($request->get());
    }

    /** 下载导入模板 */
    #[Permission(slug: 'cccms:user:template', title: '下载用户导入模板')]
    public function template(Request $request): Response
    {
        return UserLogic::template();
    }

    /** 导入 CSV */
    #[Permission(slug: 'cccms:user:import', title: '导入用户')]
    #[Restrict(methods: ['POST'])]
    public function import(Request $request): Response
    {
        $file = $request->file('file');
        if ($file === null) {
            return $this->fail('请上传 CSV 文件', 422);
        }

        return $this->ok(UserLogic::import($file), '导入完成');
    }
}
