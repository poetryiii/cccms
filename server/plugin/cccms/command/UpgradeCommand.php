<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\Upgrader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 从上游开源仓库同步框架更新（命令行入口）。
 *
 * 典型流程：
 *   php webman cccms:update --init    # 首次：登记基线，并列出「你相对上游改了哪些文件」
 *   php webman cccms:update --check   # 日常：预览上游改了什么、能不能安全覆盖
 *   php webman cccms:update           # 同步：自动覆盖「本地没改过」的文件，冲突只报告
 *   php webman cccms:update --force   # 冲突文件也覆盖（覆盖前自动备份）
 *
 * 后台「系统设置 → 自动升级」页面与本命令共用同一套实现。
 */
#[AsCommand('cccms:update', '从上游仓库同步框架更新（三方比对 + 安全覆盖 + 自动备份）')]
class UpgradeCommand extends Command
{
    /** 每类最多打印多少个文件（超出只显示计数，避免刷屏） */
    private const PREVIEW_LIMIT = 40;

    protected function configure(): void
    {
        $this
            ->addOption('init', null, InputOption::VALUE_NONE, '建立基线：记录当前代码基于的上游版本')
            ->addOption('check', 'c', InputOption::VALUE_NONE, '只比对不写文件（预览）')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, '目标版本：分支 / tag / commit')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, '同步源 key（如 gitee / github），默认取配置')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '本地也改过的冲突文件同样覆盖（先备份）')
            ->addOption('prune', null, InputOption::VALUE_NONE, '删除上游已移除且本地未改动的文件（先备份）')
            ->addOption('tags', null, InputOption::VALUE_NONE, '列出远端可用版本')
            ->addOption('status', null, InputOption::VALUE_NONE, '只输出状态摘要（供脚本 / 监控消费）')
            ->addOption('json', null, InputOption::VALUE_NONE, '以 JSON 输出')
            ->addOption('no-fetch', null, InputOption::VALUE_NONE, '不联网，使用本地缓存的上游仓库');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref    = $input->getOption('ref') ?: null;
        $source = $input->getOption('source') ?: null;
        $json   = (bool)$input->getOption('json');
        $fetch  = !$input->getOption('no-fetch');

        try {
            if ($input->getOption('tags')) {
                return $this->listTags($output, $json, $source);
            }
            if ($input->getOption('status')) {
                return $this->status($output, $json, $source);
            }
            if ($input->getOption('init')) {
                return $this->init($output, $ref, $fetch, $json, $source);
            }
            if ($input->getOption('check')) {
                return $this->check($output, $ref, $fetch, $json, $source);
            }

            return $this->sync(
                $output,
                $ref,
                $fetch,
                (bool)$input->getOption('force'),
                (bool)$input->getOption('prune'),
                $json,
                $source
            );
        } catch (Throwable $e) {
            if ($json) {
                $output->writeln((string)json_encode(
                    ['ok' => false, 'message' => $e->getMessage()],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));

                return Command::INVALID;
            }
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::INVALID;
        }
    }

    // ---------------------------------------------------------------------

    /** 首次使用：建立基线，顺带回答「我改了哪些文件」 */
    private function init(OutputInterface $output, ?string $ref, bool $fetch, bool $json, ?string $source): int
    {
        $result = Upgrader::init($ref, $fetch, $source);

        if ($json) {
            $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln('同步源：' . $result['source']['label'] . '（' . $result['source']['url'] . '）');
        $output->writeln("基线已建立：<info>{$result['ref']}</info> @ " . substr($result['commit'], 0, 8));

        if ($result['fallback'] !== '') {
            $output->writeln("<comment>上游暂不存在版本 {$result['fallback']}，已改用跟踪分支 {$result['ref']} 建立基线。</comment>");
            $output->writeln('<comment>建议给上游打上正式 tag（git tag -a v0.0.1 -m "..."），把版本固定下来。</comment>');
        }

        $output->writeln("上游文件 {$result['total']} 个，其中：");
        $output->writeln("  本地已修改 <comment>" . count($result['modified']) . '</comment>');
        $output->writeln("  本地已缺失 <comment>" . count($result['missing']) . '</comment>');
        $output->writeln("  本地独有（新增）<comment>" . count($result['localOnly']) . '</comment>');

        $this->printList($output, '本地相对基线改过的文件', $result['modified']);
        $this->printList($output, '本地新增的文件', $result['localOnly']);

        $output->writeln('');
        $output->writeln('<info>下次用 `php webman cccms:update --check` 预览上游更新。</info>');
        $output->writeln('状态文件：' . Upgrader::settings()['state_file']);

        return Command::SUCCESS;
    }

    /** 只比对不写入 */
    private function check(OutputInterface $output, ?string $ref, bool $fetch, bool $json, ?string $source): int
    {
        $plan = Upgrader::plan($ref, $fetch, $source);

        if ($json) {
            $output->writeln((string)json_encode([
                'ok'        => true,
                'source'    => $plan['source'],
                'ref'       => $plan['ref'],
                'commit'    => $plan['commit'],
                'base'      => $plan['base'],
                'summary'   => $plan['summary'],
                'lines'     => $plan['lines'],
                'items'     => $plan['items'],
                'commits'   => $plan['commits'],
                'localOnly' => $plan['localOnly'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderPlan($output, $plan);
            $output->writeln('');
            $output->writeln('<comment>当前为预览模式，未写入任何文件。</comment>');
        }

        return $plan['summary'][Upgrader::CONFLICT] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** 执行同步 */
    private function sync(
        OutputInterface $output,
        ?string $ref,
        bool $fetch,
        bool $force,
        bool $prune,
        bool $json,
        ?string $source
    ): int {
        $plan = Upgrader::plan($ref, $fetch, $source);

        // 没有任何可写入项时不必落盘，直接报告
        $actionable = $plan['summary'][Upgrader::SAFE]
            + $plan['summary'][Upgrader::NEW]
            + ($force ? $plan['summary'][Upgrader::CONFLICT] : 0)
            + ($prune ? $plan['summary'][Upgrader::REMOVED] : 0);

        if ($actionable === 0) {
            if ($json) {
                $output->writeln((string)json_encode([
                    'ok' => true, 'written' => 0, 'summary' => $plan['summary'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->renderPlan($output, $plan);
                $output->writeln('');
                $output->writeln('<info>没有需要写入的文件。</info>');
            }

            return $plan['summary'][Upgrader::CONFLICT] > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $result = Upgrader::apply($plan, $force, $prune);

        if ($json) {
            $output->writeln((string)json_encode([
                'ok'      => true,
                'ref'     => $plan['ref'],
                'written' => $result['written'],
                'removed' => $result['removed'],
                'skipped' => $result['skipped'],
                'summary' => $plan['summary'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderPlan($output, $plan);
            $output->writeln('');
            $output->writeln("<info>同步完成：写入 {$result['written']}，删除 {$result['removed']}，备份 {$result['backed']}。</info>");

            if ($result['backupDir'] !== '') {
                $output->writeln('备份目录：' . $result['backupDir']);
                $output->writeln('报告文件：' . $result['report']);
            }

            if ($result['skipped'] !== []) {
                $output->writeln('');
                $output->writeln('<comment>以下文件本地也改过（或上游已移除），已跳过：</comment>');
                $this->printList($output, '需人工合并', $result['skipped']);

                $output->writeln('');
                $output->writeln('  覆盖冲突：<comment>php webman cccms:update --force</comment>（会先备份）');
                $output->writeln('  删除文件：<comment>php webman cccms:update --prune</comment>');
            }

            $output->writeln('');
            $output->writeln('<info>后续：php webman cccms:db-upgrade && php webman cccms:menu-sync && php webman cccms:perm-scan</info>');
        }

        return $result['skipped'] !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    /** 监控友好的只读状态 */
    private function status(OutputInterface $output, bool $json, ?string $source): int
    {
        $status = Upgrader::status($source);

        if ($json) {
            $output->writeln((string)json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $status['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln('基线版本：' . ($status['base'] !== '' ? $status['base'] : '（未建立）'));
        $output->writeln('目标版本：' . ($status['ref'] !== '' ? $status['ref'] : '—'));
        $output->writeln('状态：' . $status['message']);

        return $status['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** 远端可用版本 */
    private function listTags(OutputInterface $output, bool $json, ?string $source): int
    {
        $tags = Upgrader::remoteTags($source);

        if ($json) {
            $output->writeln((string)json_encode(['ok' => true, 'tags' => $tags], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($tags === []) {
            $output->writeln('<comment>远端暂无 tag（仓库可能尚未发布版本）。</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('远端可用版本：');
        foreach ($tags as $tag) {
            $output->writeln('  ' . $tag);
        }

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------

    private function renderPlan(OutputInterface $output, array $plan): void
    {
        $output->writeln('同步源：' . (string)($plan['source']['label'] ?? ''));
        $output->writeln('当前基线：' . ($plan['base'] !== '' ? $plan['base'] : '（未知）'));
        $output->writeln('目标版本：<info>' . $plan['ref'] . '</info> @ ' . substr((string)$plan['commit'], 0, 8));

        $lines = (array)($plan['lines'] ?? []);
        if (($lines['added'] ?? 0) > 0 || ($lines['deleted'] ?? 0) > 0) {
            $output->writeln("上游改动：<info>+" . $lines['added'] . '</info> / <comment>-' . $lines['deleted'] . '</comment>');
        }

        $summary = (array)$plan['summary'];
        $output->writeln('');
        $output->writeln('比对结果：');

        foreach (Upgrader::ORDER as $kind) {
            $output->writeln(sprintf('  %-16s %d', Upgrader::LABELS[$kind], $summary[$kind] ?? 0));
        }

        $groups = [];
        foreach ((array)$plan['items'] as $path => $kind) {
            if ($kind === Upgrader::SAME) {
                continue;
            }
            $groups[$kind][] = $path;
        }

        foreach (Upgrader::ORDER as $kind) {
            if ($kind === Upgrader::SAME) {
                continue;
            }
            $this->printList($output, Upgrader::LABELS[$kind], $groups[$kind] ?? []);
        }

        if (($plan['localOnly'] ?? []) !== []) {
            $this->printList($output, '本地独有（不会改动）', $plan['localOnly']);
        }

        if (($plan['commits'] ?? []) !== []) {
            $output->writeln('');
            $output->writeln('提交记录（' . count($plan['commits']) . '）：');
            foreach (array_slice($plan['commits'], 0, self::PREVIEW_LIMIT) as $commit) {
                $output->writeln("  {$commit['short']}  {$commit['date']}  {$commit['message']}");
            }
        }
    }

    /** @param array<int,string> $paths */
    private function printList(OutputInterface $output, string $title, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $output->writeln('');
        $output->writeln("{$title}（" . count($paths) . '）：');

        foreach (array_slice($paths, 0, self::PREVIEW_LIMIT) as $path) {
            $output->writeln('  ' . $path);
        }

        $rest = count($paths) - self::PREVIEW_LIMIT;
        if ($rest > 0) {
            $output->writeln("  ... 另有 {$rest} 个");
        }
    }
}
