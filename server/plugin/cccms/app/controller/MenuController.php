<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\MenuLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class MenuController extends BaseController
{
    #[Permission(slug: 'cccms:menu:tree', title: '菜单树')]
    public function tree(Request $request): Response
    {
        // trashed=1 → 回收站视图（平铺已删节点）；角色授权树走的是不带参的普通调用
        return $this->ok(MenuLogic::tree((bool)$request->get('trashed', false)));
    }

    /** 当前登录用户的菜单树（前端动态路由），登录即可。 */
    #[NoAuth]
    public function userTree(Request $request): Response
    {
        return $this->ok(MenuLogic::userTree($request->user));
    }

    #[Permission(slug: 'cccms:menu:save', title: '新增菜单')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => MenuLogic::create($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:menu:update', title: '更新菜单')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        MenuLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:menu:delete', title: '删除菜单')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        MenuLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }
}
