<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\PostLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
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
        return $this->ok(['id' => PostLogic::create($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:post:update', title: '更新岗位')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        PostLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:post:delete', title: '删除岗位')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        PostLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }

    // ---- 批量操作（越权行 / 下挂用户的岗位自动跳过并回报） ----

    #[Permission(slug: 'cccms:post:batch_status', title: '批量启停岗位')]
    #[Restrict(methods: ['POST'])]
    public function batchStatus(Request $request): Response
    {
        $result = PostLogic::batchStatus((array)$request->post('ids', []), (int)$request->post('status', 1));

        return $this->ok($result, I18n::t('common.updated_count', ['count' => $result['affected']]));
    }

    #[Permission(slug: 'cccms:post:batch_delete', title: '批量删除岗位')]
    #[Restrict(methods: ['POST'])]
    public function batchDelete(Request $request): Response
    {
        $result = PostLogic::batchDelete((array)$request->post('ids', []));

        return $this->ok($result, I18n::t('common.deleted_count', ['count' => $result['affected']]));
    }
}
