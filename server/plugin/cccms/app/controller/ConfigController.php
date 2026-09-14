<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\ConfigLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class ConfigController extends BaseController
{
    #[Permission(slug: 'cccms:config:index', title: '配置列表')]
    public function index(Request $request): Response
    {
        return $this->ok(ConfigLogic::list((string)$request->input('group', '')));
    }

    /**
     * 前端初始化用的公开配置（品牌信息 + UI 默认值）。
     *
     * #[NoLogin]：登录页在拿到 token 前就需要系统名称/Logo/主题色/维护公告。
     * 返回的是白名单字段，不包含 security / upload 等敏感配置。
     */
    #[NoLogin]
    public function ui(Request $request): Response
    {
        return $this->ok(ConfigLogic::ui());
    }

    #[Permission(slug: 'cccms:config:save', title: '保存配置')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        $updated = ConfigLogic::save((array)$request->post());
        return $this->ok(['updated' => $updated], '保存成功');
    }
}
