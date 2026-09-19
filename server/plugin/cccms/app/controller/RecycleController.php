<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\RecycleLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 回收站（软删除数据的恢复 / 彻底删除）。
 *
 * 只有「写」操作：列表由各模块自己的接口 + `trashed=1` 提供（同一张表换数据源），
 * 所以这里不需要列表接口，也就不用「查看回收站」这个权限点 ——
 * 能看列表的人本来就受各模块 `xxx:index` 控制，能不能动这些数据才由下面两个权限决定。
 *
 * 因为原「回收站」菜单节点已删除，permission 必须用 `group` 显式指定归属菜单，
 * 否则 perm-scan 按 slug 前缀找不到父节点会直接报错。
 */
class RecycleController extends BaseController
{
    #[Permission(slug: 'cccms:recycle:restore', title: '恢复回收站数据', group: 'cccms:setting')]
    #[Restrict(methods: ['POST'])]
    public function restore(Request $request): Response
    {
        $count = RecycleLogic::restore((string)$request->post('type', ''), (array)$request->post('ids', []));

        return $this->ok(['restored' => $count], I18n::t('common.restored_count', ['count' => $count]));
    }

    #[Permission(slug: 'cccms:recycle:delete', title: '彻底删除回收站数据', group: 'cccms:setting')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $count = RecycleLogic::forceDelete((string)$request->post('type', ''), (array)$request->post('ids', []));

        return $this->ok(['deleted' => $count], I18n::t('common.purged_count', ['count' => $count]));
    }
}
