<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\MenuSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand('cccms:menu-sync', '同步 db/menu.php 目录/菜单节点')]
class MenuSyncCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = MenuSyncer::syncAll();
        } catch (Throwable $e) {
            $output->writeln('<error>同步失败：' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(
            "<info>同步完成：新增 {$result['created']}，更新 {$result['updated']}，清理 {$result['removed']}</info>"
        );

        // 按插件明细，便于多插件环境下定位变更来源
        foreach ($result['perPlugin'] ?? [] as $plugin => $detail) {
            $output->writeln(
                "  - {$plugin}: 新增 {$detail['created']}，更新 {$detail['updated']}，清理 {$detail['removed']}"
            );
        }

        return Command::SUCCESS;
    }
}
