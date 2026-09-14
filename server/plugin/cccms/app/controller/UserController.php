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
        return $this->ok(UserLogic::paginate($request->get(), $request->user));
    }

    #[Permission(slug: 'cccms:user:read', title: '用户详情')]
    public function read(Request $request): Response
    {
        return $this->ok(UserLogic::read((int)$request->input('id', 0), $request->user));
    }

    #[Permission(slug: 'cccms:user:save', title: '新增用户')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => UserLogic::create($request->post(), $request->user)], '创建成功');
    }

    #[Permission(slug: 'cccms:user:update', title: '更新用户')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        UserLogic::update((int)$request->input('id', 0), $request->post(), $request->user);
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
            (string)$request->post('password', ''),
            $request->user
        );
        return $this->ok(null, '重置成功');
    }
}
