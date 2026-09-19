<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\TenantLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 租户管理（平台级）。
 *
 * 每个方法都先过 `TenantLogic::assertPlatformAdmin()`：**处于平台租户的超管**才可操作。
 * 光有 `cccms:tenant:*` 权限点不够 —— 一个在租户内运营的账号即使被授予了节点，
 * 也不该看到 / 改动其他租户的档案（那是平台的事）。
 */
class TenantController extends BaseController
{
    #[Permission(slug: 'cccms:tenant:index', title: '租户列表')]
    public function index(Request $request): Response
    {
        TenantLogic::assertPlatformAdmin($request->user);

        return $this->ok(TenantLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:tenant:read', title: '租户详情')]
    public function read(Request $request): Response
    {
        TenantLogic::assertPlatformAdmin($request->user);

        return $this->ok(TenantLogic::read((int)$request->input('id', 0)));
    }

    #[Permission(slug: 'cccms:tenant:save', title: '新增租户')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        TenantLogic::assertPlatformAdmin($request->user);

        return $this->ok(['id' => TenantLogic::create($request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:tenant:update', title: '更新租户')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        TenantLogic::assertPlatformAdmin($request->user);

        TenantLogic::update((int)$request->input('id', 0), $request->post());

        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:tenant:delete', title: '删除租户')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        TenantLogic::assertPlatformAdmin($request->user);

        TenantLogic::delete((int)$request->input('id', 0));

        return $this->ok(null, I18n::t('common.deleted'));
    }
}