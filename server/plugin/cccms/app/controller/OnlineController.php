<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\OnlineLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

/** 在线用户（基于 Redis 会话索引，支持强制下线）。 */
class OnlineController extends BaseController
{
    #[Permission(slug: 'cccms:online:index', title: '在线用户')]
    public function index(Request $request): Response
    {
        return $this->ok(OnlineLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:online:kick', title: '强制下线')]
    #[Restrict(methods: ['POST'])]
    public function kick(Request $request): Response
    {
        OnlineLogic::kick((string)$request->post('jti', ''), (string)($request->jti ?? ''));

        return $this->ok(null, '已强制下线');
    }

    #[Permission(slug: 'cccms:online:kick_user', title: '强制用户全部下线')]
    #[Restrict(methods: ['POST'])]
    public function kickUser(Request $request): Response
    {
        $sessions = OnlineLogic::kickUser((int)$request->post('user_id', 0), $request->user);

        return $this->ok(['sessions' => $sessions], '已强制下线');
    }
}
