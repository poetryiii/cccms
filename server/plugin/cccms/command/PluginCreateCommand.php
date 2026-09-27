<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\MenuSyncer;
use plugin\cccms\support\PermScanner;
use plugin\cccms\support\PluginScaffolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Throwable;

/**
 * 生成业务插件骨架，并自动纳管（菜单 + 权限节点）。
 *
 * 设计取舍：落盘逻辑放在 `support/PluginScaffolder`（纯函数、可单测），命令只负责
 * 交互与调用；纳管直接复用 `MenuSyncer` / `PermScanner`，与 `cccms:menu-sync` /
 * `cccms:perm-scan` 命令**共用同一份实现**，避免「命令行跑一遍、命令生成又跑一遍结果不一致」。
 */
#[AsCommand('cccms:plugin-create', '生成业务插件骨架并登记菜单')]
class PluginCreateCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, '插件名（小写字母开头，如 shop）');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, '插件类型：backend（后台，默认）| frontend（前台公开）');
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, '插件显示名（菜单目录标题，默认由插件名推导）');
        $this->addOption('module', null, InputOption::VALUE_REQUIRED, '示例模块名（默认 demo）');
        $this->addOption('module-title', null, InputOption::VALUE_REQUIRED, '示例模块标题（默认「示例」）');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, '插件目录已存在时覆盖同名文件');
        $this->addOption('no-sync', null, InputOption::VALUE_NONE, '生成后不自动执行菜单 / 权限同步');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $this->resolveType($input, $output);

        $config = [
            'name'         => (string)$input->getArgument('name'),
            'type'         => $type,
            'title'        => (string)($input->getOption('title') ?? ''),
            'module'       => (string)($input->getOption('module') ?? ''),
            'module_title' => (string)($input->getOption('module-title') ?? ''),
            'force'        => (bool)$input->getOption('force'),
        ];

        try {
            $files = PluginScaffolder::create($config);
        } catch (ApiException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (Throwable $e) {
            $output->writeln('<error>生成失败：' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>插件骨架已生成：</info>');
        foreach ($files as $file) {
            $output->writeln("  - {$file}");
        }

        if ($type === PluginScaffolder::TYPE_BACKEND) {
            if ($input->getOption('no-sync')) {
                $output->writeln('<comment>已按 --no-sync 跳过自动同步，请手动执行 cccms:menu-sync 与 cccms:perm-scan。</comment>');
            } else {
                $this->sync($output);
            }
        } else {
            $output->writeln('<comment>前台（公开）插件不参与菜单 / 权限体系，已跳过自动同步。</comment>');
        }

        $this->printNextSteps($output, $config, $type);

        return Command::SUCCESS;
    }

    /**
     * 解析插件类型：显式选项 > 交互式询问 > 默认后台。
     *
     * 未传 `--type` 且未加 `--no-interaction` 时才提问；脚本 / CI 请显式传 `--type`
     * 或 `-n`，避免等待输入（Windows 下没有 `posix_isatty`，管道输入不会自动降级）。
     */
    private function resolveType(InputInterface $input, OutputInterface $output): string
    {
        $type = strtolower(trim((string)($input->getOption('type') ?? '')));
        if ($type !== '') {
            return $type;
        }

        if (!$input->isInteractive()) {
            return PluginScaffolder::TYPE_BACKEND;
        }

        $question = new ChoiceQuestion(
            '请选择插件类型',
            [
                PluginScaffolder::TYPE_BACKEND  => '后台（需登录 + 权限，自动登记菜单）',
                PluginScaffolder::TYPE_FRONTEND => '前台（公开，无菜单）',
            ],
            PluginScaffolder::TYPE_BACKEND
        );

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return (string)$helper->ask($input, $output, $question);
    }

    /**
     * 自动纳管：菜单同步 → 权限校验 → 按钮节点同步。
     *
     * 分两步是为了**失败可定位**：菜单没建出来时权限扫描会因「找不到归属菜单」整批失败，
     * 分开输出能让使用者一眼看出是哪一步没成，而不是只看到一句「同步失败」。
     * 数据库不可用等异常一律降级为提示，骨架文件已经落盘，不影响手工补跑。
     */
    private function sync(OutputInterface $output): void
    {
        try {
            $menu = MenuSyncer::syncAll();
        } catch (Throwable $e) {
            $output->writeln('<comment>菜单同步未完成：' . $e->getMessage() . '</comment>');
            $output->writeln('<comment>请稍后手动执行：php webman cccms:menu-sync</comment>');

            return;
        }
        $output->writeln("<info>菜单同步：新增 {$menu['created']}，更新 {$menu['updated']}，清理 {$menu['removed']}</info>");

        try {
            $items  = PermScanner::scanAllPlugins();
            $errors = PermScanner::validate($items);
            if ($errors) {
                foreach ($errors as $error) {
                    $output->writeln("<error>{$error}</error>");
                }
                $output->writeln('<comment>权限校验未通过，未写库；请执行 php webman cccms:perm-scan 查看详情</comment>');

                return;
            }

            $result = (new PermScanner())->sync($items);
            $output->writeln("<info>权限同步：新增 {$result['created']}，跳过 {$result['skipped']}</info>");
        } catch (Throwable $e) {
            $output->writeln('<comment>权限同步未完成：' . $e->getMessage() . '</comment>');
            $output->writeln('<comment>请稍后手动执行：php webman cccms:perm-scan</comment>');
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function printNextSteps(OutputInterface $output, array $config, string $type): void
    {
        $plugin = PluginScaffolder::normalizeName((string)$config['name']);
        $module = trim((string)$config['module']) !== '' ? strtolower(trim((string)$config['module'])) : 'demo';

        $output->writeln('');

        if ($type === PluginScaffolder::TYPE_FRONTEND) {
            $output->writeln('<info>下一步（前台公开插件）：</info>');
            $output->writeln("  1. 接口已按 /{$plugin}/ping、/{$plugin}/{$module} 暴露，直接在此扩展业务即可。");
            $output->writeln("  2. 若需登录 / 权限：改用 php webman cccms:plugin-create {$plugin} --type=backend 重新生成后台骨架。");

            return;
        }

        $menuSlug  = "{$plugin}:{$module}";
        $component = "{$plugin}/{$module}/index";

        $output->writeln('<info>下一步（后台插件）：</info>');
        $output->writeln("  1. 前端页面：frontend/src/pages/{$component}.vue");
        $output->writeln("     defineOptions({ name: '{$menuSlug}' }) 必须等于菜单 slug：{$menuSlug}");
        $output->writeln("  2. 接口封装：frontend/src/api/{$plugin}/{$module}.ts（菜单 component 已填 '{$component}'）");
        $output->writeln("  3. 表结构：server/plugin/{$plugin}/db/schema.sql，执行 php webman cccms:db-upgrade");
        $output->writeln('  4. 校验：php webman cccms:perm-scan --check 与 php webman cccms:data-scope-check');
    }
}
