<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\AuthLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\Captcha;
use plugin\cccms\support\SysConfig;
use Webman\Http\Request;
use Webman\Http\Response;

class AuthController extends BaseController
{
    #[NoLogin]
    public function ping(Request $request): Response
    {
        return $this->ok(['pong' => true, 'time' => date('Y-m-d H:i:s')]);
    }

    // title 是操作名：登录不是权限点（不产生按钮节点），但操作日志要显示「登录」
    #[NoLogin(title: '登录')]
    #[Restrict(methods: ['POST'])]
    public function login(Request $request): Response
    {
        $result = AuthLogic::login(
            (string)$request->post('username', ''),
            (string)$request->post('password', ''),
            (string)$request->post('captcha', ''),
            (string)$request->post('captcha_id', ''),
        );
        return $this->ok($result, '登录成功');
    }

    /**
     * 图形验证码。
     *
     * 未开启（security.login_captcha=0）时返回 null，
     * 前端据此隐藏验证码输入框，不需要额外接口告知。
     */
    #[NoLogin]
    public function captcha(Request $request): Response
    {
        if (!SysConfig::getBool('security.login_captcha', false)) {
            return $this->ok(null);
        }

        $captcha = Captcha::make();
        return $this->ok(['captcha_id' => $captcha['id'], 'image' => $captcha['image']]);
    }

    #[NoAuth]
    public function me(Request $request): Response
    {
        return $this->ok(AuthLogic::profile($request->user));
    }

    #[NoAuth(title: '注销')]
    #[Restrict(methods: ['POST'])]
    public function logout(Request $request): Response
    {
        AuthLogic::logout($request->user);
        return $this->ok(null, '已退出');
    }
}
