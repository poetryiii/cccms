<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\GeneratorLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class GeneratorController extends BaseController
{
    #[Permission(slug: 'cccms:generator:tables', title: '数据表列表')]
    public function tables(Request $request): Response
    {
        return $this->ok(GeneratorLogic::tables());
    }

    #[Permission(slug: 'cccms:generator:columns', title: '表字段信息')]
    public function columns(Request $request): Response
    {
        return $this->ok(GeneratorLogic::columns((string)$request->input('table', '')));
    }

    #[Permission(slug: 'cccms:generator:preview', title: '生成预览')]
    #[Restrict(methods: ['POST'])]
    public function preview(Request $request): Response
    {
        return $this->ok(GeneratorLogic::preview($request->post()));
    }

    #[Permission(slug: 'cccms:generator:generate', title: '执行生成')]
    #[Restrict(methods: ['POST'])]
    public function generate(Request $request): Response
    {
        return $this->ok(GeneratorLogic::generate($request->post()), '生成成功');
    }
}
