<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\DataRuleLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use Webman\Http\Request;
use Webman\Http\Response;

class DataRuleController extends BaseController
{
    #[Permission(slug: 'cccms:data_rule:index', title: '数据权限列表')]
    public function index(Request $request): Response
    {
        return $this->ok(DataRuleLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:data_rule:options', title: '数据权限候选项')]
    public function options(): Response
    {
        return $this->ok(DataRuleLogic::options());
    }

    #[Permission(slug: 'cccms:data_rule:users', title: '数据权限-用户搜索')]
    public function users(Request $request): Response
    {
        $ids = $request->get('ids', []);
        $ids = is_array($ids) ? $ids : explode(',', (string)$ids);

        return $this->ok(DataRuleLogic::searchUsers((string)$request->get('keyword', ''), $ids));
    }

    #[Permission(slug: 'cccms:data_rule:save', title: '新增数据权限')]
    #[Restrict(methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->ok(['id' => DataRuleLogic::create($request->post())], '创建成功');
    }

    #[Permission(slug: 'cccms:data_rule:update', title: '更新数据权限')]
    #[Restrict(methods: ['POST'])]
    public function update(Request $request): Response
    {
        DataRuleLogic::update((int)$request->input('id', 0), $request->post());
        return $this->ok(null, '更新成功');
    }

    #[Permission(slug: 'cccms:data_rule:delete', title: '删除数据权限')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        DataRuleLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, '删除成功');
    }

    /** 导出 CSV（按当前筛选，直接返回文件流而非统一信封） */
    #[Permission(slug: 'cccms:data_rule:export', title: '导出数据权限规则')]
    public function export(Request $request): Response
    {
        return DataRuleLogic::export($request->get());
    }

    /** 下载导入模板 */
    #[Permission(slug: 'cccms:data_rule:template', title: '下载数据权限规则导入模板')]
    public function template(Request $request): Response
    {
        return DataRuleLogic::template();
    }

    /** 导入 CSV */
    #[Permission(slug: 'cccms:data_rule:import', title: '导入数据权限规则')]
    #[Restrict(methods: ['POST'])]
    public function import(Request $request): Response
    {
        $file = $request->file('file');
        if ($file === null) {
            return $this->fail('请上传 CSV 文件', 422);
        }

        return $this->ok(DataRuleLogic::import($file), '导入完成');
    }
}
