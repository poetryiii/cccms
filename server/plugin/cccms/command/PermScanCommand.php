<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\PermScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand('cccms:perm-scan', '扫描权限注解并同步按钮节点')]
class PermScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, '只校验不写库');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scanner = new PermScanner();
        $items = PermScanner::scanAllPlugins();

        $output->writeln("<info>扫描到 " . count($items) . " 个方法</info>");

        $errors = PermScanner::validate($items);
        foreach ($errors as $e) {
            $output->writeln("<error>{$e}</error>");
        }
        if ($errors) {
            $output->writeln("<error>校验失败，共 " . count($errors) . " 处错误</error>");
            return Command::FAILURE;
        }

        if ($input->getOption('check')) {
            $output->writeln('<info>校验通过（--check 不写库）</info>');
            return Command::SUCCESS;
        }

        try {
            $result = $scanner->sync($items);
            $output->writeln("<info>同步完成：新增 {$result['created']}，跳过 {$result['skipped']}</info>");
        } catch (Throwable $e) {
            $output->writeln("<error>同步失败：{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $zombies = $scanner->zombieNodes($items);
        if ($zombies) {
            $output->writeln('<comment>僵尸节点（仅报告不删除）：' . implode(', ', $zombies) . '</comment>');
        }

        return Command::SUCCESS;
    }
}
