<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\NoticeLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 通知公告。
 *
 * 管理侧需要权限节点；阅读侧（我的消息、未读数、标记已读）只要登录即可 ——
 * 这几个接口只返回**当前用户自己的**数据，不涉及全量，符合「NoAuth 不下放给全量查询」的约定。
 */
class NoticeController extends BaseController
{
    // ---------------- 管理侧 ----------------

    #[Permission(slug: 'cccms:notice:index', title: '通知公告列表')]
    public function index(Request $request): Response
    {
        return $this->ok(NoticeLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:notice:read', title: '通知公告详情')]
    public function read(Request $request): Response
    {
        return $this->ok(NoticeLogic::read((int)$request->get('id', 0)));
    }

    #[Permission(slug: 'cccms:notice:save', title: '新增通知公告')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        $id = NoticeLogic::create($request->post(), $request->user);

        return $this->ok(['id' => $id], '新增成功');
    }

    #[Permission(slug: 'cccms:notice:update', title: '更新通知公告')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        NoticeLogic::update((int)$request->post('id', 0), $request->post());

        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:notice:delete', title: '删除通知公告')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        NoticeLogic::delete((int)$request->post('id', 0));

        return $this->ok(null, '已删除');
    }

    #[Permission(slug: 'cccms:notice:report', title: '通知公告已读回执报表')]
    public function report(Request $request): Response
    {
        return $this->ok(NoticeLogic::readReport((int)$request->get('id', 0), $request->get()));
    }

    #[Permission(slug: 'cccms:notice:options', title: '通知公告投放候选')]
    public function options(): Response
    {
        return $this->ok(NoticeLogic::options());
    }

    #[Permission(slug: 'cccms:notice:users', title: '通知公告用户搜索')]
    public function users(Request $request): Response
    {
        $ids = $request->get('ids', []);
        $ids = is_array($ids) ? $ids : explode(',', (string)$ids);

        return $this->ok(NoticeLogic::searchUsers((string)$request->get('keyword', ''), $ids));
    }

    // ---------------- 阅读侧（登录即可） ----------------

    #[NoAuth(title: '我的消息')]
    public function my(Request $request): Response
    {
        return $this->ok(NoticeLogic::myPaginate($request->user->id, $request->get()));
    }

    #[NoAuth]
    public function unread(Request $request): Response
    {
        return $this->ok(['count' => NoticeLogic::unreadCount($request->user->id)]);
    }

    #[NoAuth(title: '标记消息已读')]
    #[Restrict(methods: ['POST'])]
    public function markRead(Request $request): Response
    {
        NoticeLogic::markRead($request->user->id, (int)$request->post('id', 0));

        return $this->ok(null, '已标记为已读');
    }

    #[NoAuth(title: '全部标记已读')]
    #[Restrict(methods: ['POST'])]
    public function markAllRead(Request $request): Response
    {
        return $this->ok(['count' => NoticeLogic::markAllRead($request->user->id)], '已全部标记为已读');
    }
}
