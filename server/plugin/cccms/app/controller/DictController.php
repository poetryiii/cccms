<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\CategoryLogic;
use plugin\cccms\app\logic\DictLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class DictController extends BaseController
{
    #[Permission(slug: 'cccms:dict:index', title: '字典类型列表')]
    public function index(Request $request): Response
    {
        return $this->ok(DictLogic::typePaginate($request->get()));
    }

    #[Permission(slug: 'cccms:dict:save', title: '新增字典类型')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => DictLogic::typeCreate($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:dict:update', title: '更新字典类型')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        DictLogic::typeUpdate((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:dict:delete', title: '删除字典类型')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        DictLogic::typeDelete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }

    #[Permission(slug: 'cccms:dict:data', title: '字典数据列表')]
    public function data(Request $request): Response
    {
        // trashed=1 → 回收站视图（只看该类型下已删的数据）
        return $this->ok(DictLogic::dataList(
            (int)$request->input('type_id', 0),
            (bool)$request->input('trashed', false)
        ));
    }

    #[Permission(slug: 'cccms:dict:save_data', title: '新增字典数据')]
    #[Restrict(methods: ['POST'])]
    public function saveData(Request $request): Response
    {
        return $this->ok(['id' => DictLogic::dataCreate($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:dict:update_data', title: '更新字典数据')]
    #[Restrict(methods: ['POST'])]
    public function updateData(Request $request): Response
    {
        DictLogic::dataUpdate((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:dict:delete_data', title: '删除字典数据')]
    #[Restrict(methods: ['POST'])]
    public function deleteData(Request $request): Response
    {
        DictLogic::dataDelete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }

    // ---- 字典分类 ----
    // 与附件分类共用 sys_category 表与 CategoryLogic，但 slug 按模块拆开，
    // 这样「只有附件权限」的角色不会因为分类权限挂错菜单而被牵连。

    #[Permission(slug: 'cccms:dict:category', title: '字典分类列表')]
    public function category(Request $request): Response
    {
        return $this->ok(CategoryLogic::tree('dict', (bool)$request->get('trashed', false)));
    }

    #[Permission(slug: 'cccms:dict:category_save', title: '新增字典分类')]
    #[Restrict(methods: ['POST'])]
    public function categorySave(Request $request): Response
    {
        return $this->ok(['id' => CategoryLogic::create('dict', $request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:dict:category_update', title: '更新字典分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryUpdate(Request $request): Response
    {
        CategoryLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:dict:category_delete', title: '删除字典分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryDelete(Request $request): Response
    {
        CategoryLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }
}
