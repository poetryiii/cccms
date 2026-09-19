<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\DataScopeTableLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 数据权限「受控表」维护。
 *
 * slug 刻意用 `cccms:data_rule:table_*`：按最长前缀会自动挂到「数据权限」菜单下，
 * 不需要额外加菜单节点。
 */
class DataScopeTableController extends BaseController
{
    #[Permission(slug: 'cccms:data_rule:table_index', title: '受控表列表')]
    public function index(): Response
    {
        return $this->ok(DataScopeTableLogic::index());
    }

    #[Permission(slug: 'cccms:data_rule:table_save', title: '新增受控表')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => DataScopeTableLogic::save($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:data_rule:table_update', title: '更新受控表')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        DataScopeTableLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:data_rule:table_delete', title: '移除受控表')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $rules = DataScopeTableLogic::delete((int)$request->input('id', 0));
        $msg   = $rules > 0
            ? I18n::t('data_scope_table.removed_with_rules', ['count' => $rules])
            : I18n::t('common.deleted');

        return $this->ok(['rules' => $rules], $msg);
    }
}
