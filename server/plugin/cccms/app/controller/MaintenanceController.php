<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\MaintenanceLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 系统维护（顶栏「同步/清理缓存」按钮）。
 *
 * slug 用 `cccms:config:*`：按最长前缀会挂到「配置管理」菜单下，
 * 因此不需要额外新增菜单节点。
 */
class MaintenanceController extends BaseController
{
    #[Permission(slug: 'cccms:config:refresh', title: '同步菜单/清理缓存')]
    #[Restrict(methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        return $this->ok(
            MaintenanceLogic::refresh((string)$request->post('scope', 'all')),
            I18n::t('maintenance.refresh_done')
        );
    }
}
