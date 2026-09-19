<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\UpgradeLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 自动升级（上游框架更新）。
 *
 * 接口与命令行 `php webman cccms:update` 一一对应：
 *   index  ≈ 状态概览（含当前版本 / 可用源）
 *   check  ≈ --check（只比对不写文件）
 *   init   ≈ --init（建立基线）
 *   run    ≈ 直接执行同步（force / prune 由页面上的勾选项决定）
 */
class UpgradeController extends BaseController
{
    #[Permission(slug: 'cccms:upgrade:index', title: '自动升级概览')]
    public function index(Request $request): Response
    {
        return $this->ok(UpgradeLogic::overview());
    }

    #[Permission(slug: 'cccms:upgrade:check', title: '检查上游更新')]
    public function check(Request $request): Response
    {
        return $this->ok(UpgradeLogic::check(
            (string)$request->get('source', ''),
            $request->get('ref') ?: null
        ));
    }

    #[Permission(slug: 'cccms:upgrade:tags', title: '远端版本列表')]
    public function tags(Request $request): Response
    {
        return $this->ok(['tags' => UpgradeLogic::tags((string)$request->get('source', ''))]);
    }

    #[Permission(slug: 'cccms:upgrade:init', title: '初始化升级基线')]
    #[Restrict(methods: ['POST'])]
    public function init(Request $request): Response
    {
        return $this->ok(
            UpgradeLogic::init(
                (string)$request->post('source', ''),
                $request->post('ref') ?: null
            ),
            I18n::t('upgrade.baseline_created')
        );
    }

    #[Permission(slug: 'cccms:upgrade:run', title: '执行升级')]
    #[Restrict(methods: ['POST'])]
    public function run(Request $request): Response
    {
        $result = UpgradeLogic::run(
            (string)$request->post('source', ''),
            $request->post('ref') ?: null,
            // 前端传的是 JSON 布尔，但保险起见按字符串解析（避免 'false' 被当成 true）
            filter_var($request->post('force', false), FILTER_VALIDATE_BOOL),
            filter_var($request->post('prune', false), FILTER_VALIDATE_BOOL)
        );

        $message = $result['written'] > 0 || $result['removed'] > 0
            ? I18n::t('upgrade.done', [
                'written' => $result['written'],
                'removed' => $result['removed'],
            ])
            : I18n::t('upgrade.no_changes');

        return $this->ok($result, $message);
    }
}
