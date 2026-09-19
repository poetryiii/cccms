<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\DeptLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class DeptController extends BaseController
{
    #[Permission(slug: 'cccms:dept:tree', title: '部门树')]
    public function tree(Request $request): Response
    {
        // trashed=1 → 回收站视图（平铺已删部门）
        return $this->ok(DeptLogic::tree((bool)$request->get('trashed', false)));
    }

    #[Permission(slug: 'cccms:dept:save', title: '新增部门')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => DeptLogic::create($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:dept:update', title: '更新部门')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        DeptLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:dept:delete', title: '删除部门')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        DeptLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }
}
