<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\SqlFileRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 初始化 / 更新数据库（幂等，可重复执行）。
 *
 * db/schema.sql（CREATE TABLE IF NOT EXISTS）与 db/seed.sql
 * （INSERT IGNORE / INSERT ... WHERE NOT EXISTS）是数据库的唯一权威定义，
 * 本命令直接执行它们，再执行各业务插件的 schema.sql / seed.sql。
 */
#[AsCommand('cccms:db-upgrade', '初始化/更新数据库结构与种子数据（幂等，可重复执行）')]
class DbUpgradeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // cccms 自身：先建表（schema）再灌种子（seed）
        $base = base_path() . '/plugin/cccms/db/';
        foreach (['schema.sql', 'seed.sql'] as $name) {
            try {
                $n = SqlFileRunner::run($base . $name);
                $output->writeln("  <info>核心 SQL</info> {$name}（{$n} 条）");
            } catch (Throwable $e) {
                $output->writeln("  <error>核心 SQL 失败</error> {$name}：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }

        // 业务插件建表：执行 plugin/*/db/schema.sql（幂等）
        $pluginStatements = 0;
        foreach (SqlFileRunner::pluginSchemaFiles() as $file) {
            $plugin = SqlFileRunner::pluginNameOf($file);
            try {
                $n = SqlFileRunner::run($file);
                $pluginStatements += $n;
                $output->writeln("  <info>插件 schema</info> {$plugin}（{$n} 条）");
            } catch (Throwable $e) {
                $output->writeln("  <error>插件 schema 失败</error> {$plugin}：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }
        if ($pluginStatements > 0) {
            $output->writeln("<info>业务插件表结构：共执行 {$pluginStatements} 条语句</info>");
        }

        // 业务插件种子数据：执行 plugin/*/db/seed.sql（幂等）
        $pluginSeeds = 0;
        foreach (SqlFileRunner::pluginSeedFiles() as $file) {
            $plugin = SqlFileRunner::pluginNameOf($file);
            try {
                $n = SqlFileRunner::run($file);
                $pluginSeeds += $n;
                $output->writeln("  <info>插件 seed</info> {$plugin}（{$n} 条）");
            } catch (Throwable $e) {
                $output->writeln("  <error>插件 seed 失败</error> {$plugin}：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }
        if ($pluginSeeds > 0) {
            $output->writeln("<info>业务插件种子数据：共执行 {$pluginSeeds} 条语句</info>");
        }

        return Command::SUCCESS;
    }
}
