<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\RoleLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class RoleController extends BaseController
{
    #[Permission(slug: 'cccms:role:index', title: '角色列表')]
    public function index(Request $request): Response
    {
        return $this->ok(RoleLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:role:tree', title: '角色树')]
    public function tree(Request $request): Response
    {
        return $this->ok(RoleLogic::tree());
    }

    #[Permission(slug: 'cccms:role:read', title: '角色详情')]
    public function read(Request $request): Response
    {
        return $this->ok(RoleLogic::read((int)$request->input('id', 0)));
    }

    #[Permission(slug: 'cccms:role:save', title: '新增角色')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => RoleLogic::create($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:role:update', title: '更新角色')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        RoleLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:role:delete', title: '删除角色')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        RoleLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }
}
