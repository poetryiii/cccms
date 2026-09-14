<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\PostLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class PostController extends BaseController
{
    #[Permission(slug: 'cccms:post:index', title: '岗位列表')]
    public function index(Request $request): Response
    {
        return $this->ok(PostLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:post:save', title: '新增岗位')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => PostLogic::create($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:post:update', title: '更新岗位')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        PostLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:post:delete', title: '删除岗位')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        PostLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }
}
