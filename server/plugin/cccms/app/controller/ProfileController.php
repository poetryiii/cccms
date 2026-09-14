<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\ProfileLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 个人中心。
 *
 * 用 `#[NoAuth]`（登录即可，不产生按钮节点）：每个登录用户都应该能看/改自己的资料，
 * 不挂在任何权限点上；页面本身是前端静态路由，因此也不会出现在左侧菜单里。
 */
class ProfileController extends BaseController
{
    #[NoAuth]
    public function index(Request $request): Response
    {
        return $this->ok(ProfileLogic::read($request->user));
    }

    #[NoAuth(title: '修改个人资料')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        ProfileLogic::update($request->user, $request->post());

        return $this->ok(null, '保存成功');
    }

    #[NoAuth(title: '修改密码')]
    #[Restrict(methods: ['POST'])]
    public function password(Request $request): Response
    {
        ProfileLogic::changePassword(
            $request->user,
            (string)$request->post('old_password', ''),
            (string)$request->post('new_password', '')
        );

        return $this->ok(null, '密码已修改');
    }
}
