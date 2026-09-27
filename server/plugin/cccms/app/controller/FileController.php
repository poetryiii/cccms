<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\CategoryLogic;
use plugin\cccms\app\logic\FileLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\ChunkUpload;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class FileController extends BaseController
{
    #[Permission(slug: 'cccms:file:index', title: '附件列表')]
    public function index(Request $request): Response
    {
        return $this->ok(FileLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:file:upload', title: '上传附件')]
    #[Restrict(methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file) {
            throw new ApiException(I18n::t('file.file_required'), 422);
        }
        // 带 category_id 时直接归入左侧选中的分类
        return $this->ok(
            FileLogic::upload($file, $request->user->id, (int)$request->input('category_id', 0)),
            I18n::t('file.uploaded')
        );
    }

    /**
     * 分片上传第一步：校验类型 / 大小 → 尝试秒传 → 建会话。
     *
     * 分片上传有独立权限节点 `cccms:file:upload:chunk`：它与单请求的 `cccms:file:upload`
     * 是两个按钮节点，管理员可以只给「传大文件」的岗位开分片、不给普通岗位开。
     * 后两步 `chunk` / `complete` 用 `#[NoAuth]`（登录即可）——它们没有独立的可授权语义，
     * 会话只能由「通过了 `init` 校验的本人」创建，`ChunkUpload::load()` 还会校验归属，
     * 因此没有节点也不会被越权调用。
     */
    #[Permission(slug: 'cccms:file:upload:chunk', title: '分片上传')]
    #[Restrict(methods: ['POST'])]
    public function uploadInit(Request $request): Response
    {
        return $this->ok(ChunkUpload::init($request->post(), $request->user->id));
    }

    /** 分片上传第二步：接收一片（幂等，可重传） */
    #[NoAuth(title: '分片上传')]
    #[Restrict(methods: ['POST'])]
    public function uploadChunk(Request $request): Response
    {
        return $this->ok(ChunkUpload::receive(
            (string)$request->post('upload_id', ''),
            (int)$request->post('index', -1),
            $request->file('chunk'),
            $request->user->id
        ));
    }

    /** 分片上传第三步：合并入库（校验片齐 / 大小 / 哈希） */
    #[NoAuth(title: '分片上传')]
    #[Restrict(methods: ['POST'])]
    public function uploadComplete(Request $request): Response
    {
        $result = ChunkUpload::complete((string)$request->post('upload_id', ''), $request->user->id);

        return $this->ok($result, I18n::t('file.uploaded'));
    }

    #[Permission(slug: 'cccms:file:delete', title: '删除附件')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        FileLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }

    #[Permission(slug: 'cccms:file:move', title: '移动附件到分类')]
    #[Restrict(methods: ['POST'])]
    public function move(Request $request): Response
    {
        $count = FileLogic::move(
            (array)$request->input('ids', []),
            (int)$request->input('category_id', 0)
        );
        return $this->ok(['count' => $count], I18n::t('common.moved'));
    }

    // ---- 附件分类 ----
    // 与字典分类共用 sys_category 表与 CategoryLogic，slug 按模块拆开以便独立授权。

    #[Permission(slug: 'cccms:file:category', title: '附件分类列表')]
    public function category(Request $request): Response
    {
        return $this->ok(CategoryLogic::tree('file', (bool)$request->get('trashed', false)));
    }

    #[Permission(slug: 'cccms:file:category_save', title: '新增附件分类')]
    #[Restrict(methods: ['POST'])]
    public function categorySave(Request $request): Response
    {
        return $this->ok(['id' => CategoryLogic::create('file', $request->post())], I18n::t('common.created'));
    }

    #[Permission(slug: 'cccms:file:category_update', title: '更新附件分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryUpdate(Request $request): Response
    {
        CategoryLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, I18n::t('common.updated'));
    }

    #[Permission(slug: 'cccms:file:category_delete', title: '删除附件分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryDelete(Request $request): Response
    {
        CategoryLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }
}
