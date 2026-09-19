<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\UserLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
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
        return $this->ok(['id' => UserLogic::create($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:user:update', title: '更新用户')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        UserLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:user:delete', title: '删除用户')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        UserLogic::delete((int)$request->input('id', 0), $request->user);
        return $this->ok(null, I18n::t('common.deleted'));
    }

    #[Permission(slug: 'cccms:user:reset_password', title: '重置密码')]
    #[Restrict(methods: ['POST'])]
    public function resetPassword(Request $request): Response
    {
        UserLogic::resetPassword(
            (int)$request->input('id', 0),
            (string)$request->post('password', '')
        );
        return $this->ok(null, I18n::t('common.reset'));
    }

    // ---- 批量操作（越权行自动跳过并回报，不整批失败） ----

    /** 批量启用 / 禁用 */
    #[Permission(slug: 'cccms:user:batch_status', title: '批量启停用户')]
    #[Restrict(methods: ['POST'])]
    public function batchStatus(Request $request): Response
    {
        $result = UserLogic::batchStatus(
            (array)$request->post('ids', []),
            (int)$request->post('status', 1),
            $request->user
        );

        return $this->ok($result, I18n::t('common.updated_count', ['count' => $result['affected']]));
    }

    #[Permission(slug: 'cccms:user:batch_delete', title: '批量删除用户')]
    #[Restrict(methods: ['POST'])]
    public function batchDelete(Request $request): Response
    {
        $result = UserLogic::batchDelete((array)$request->post('ids', []), $request->user);

        return $this->ok($result, I18n::t('common.deleted_count', ['count' => $result['affected']]));
    }

    /** 批量分配角色 / 部门 / 岗位 */
    #[Permission(slug: 'cccms:user:batch_assign', title: '批量分配')]
    #[Restrict(methods: ['POST'])]
    public function batchAssign(Request $request): Response
    {
        $result = UserLogic::batchAssign((array)$request->post('ids', []), $request->post());

        return $this->ok($result, I18n::t('common.assigned_count', ['count' => $result['affected']]));
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
            return $this->fail(I18n::t('user.upload_csv_required'), 422);
        }

        return $this->ok(UserLogic::import($file), I18n::t('common.imported'));
    }
}
