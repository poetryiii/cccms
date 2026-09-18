<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\LogArchiver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 归档历史日志到对象存储（**破坏性**：上传成功后删除主库记录）。
 *
 * 与内置任务「清理历史日志」的区别：那个直接删；本命令先把冷数据存到对象存储再删，
 * 适合长期留存 / 合规审计。参数默认取 plugin/cccms/config/log.php 的 archive 段。
 *
 * 用法：
 *   php webman cccms:log-archive --dry-run              # 只统计，不落盘不删除
 *   php webman cccms:log-archive --days=30 --batch=1000
 *   php webman cccms:log-archive --path=/auth/login      # 只归档登录日志
 *
 * 安全：先上传成功、再按 id 删除；上传失败立即中止，绝不「删了没存」。
 */
#[AsCommand('cccms:log-archive', '归档历史日志到对象存储并删除主库记录（先存后删）')]
class LogArchiveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, '归档创建时间早于 N 天的记录（默认取配置，且不小于 ' . LogArchiver::MIN_DAYS . '）')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, '每批处理条数（默认取配置，兜底 1000）')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只统计，不落盘不删除')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, '只归档该请求路径的记录（精确匹配，如 /auth/login），默认全部');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'dry_run' => (bool)$input->getOption('dry-run'),
            'path'    => (string)($input->getOption('path') ?: ''),
        ];
        if ($input->getOption('days') !== null) {
            $options['days'] = (int)$input->getOption('days');
        }
        if ($input->getOption('batch') !== null) {
            $options['batch'] = (int)$input->getOption('batch');
        }

        try {
            $result = LogArchiver::archive($options);
        } catch (ApiException $e) {
            $output->writeln('<error>归档失败：' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (Throwable $e) {
            $output->writeln('<error>归档异常：' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>扫描 %d 条、归档 %d 条、上传文件 %d 个、剩余未归档 %d 条%s</info>',
            $result['scanned'],
            $result['archived'],
            $result['files'],
            $result['remaining'],
            $result['dry_run'] ? '（dry-run，未落盘未删除）' : ''
        ));
        $output->writeln(sprintf(
            '路径=%s（空=全部），截止时间=%s，存储驱动=%s',
            $result['path'] !== '' ? $result['path'] : '全部',
            $result['cutoff'],
            $result['driver']
        ));

        return Command::SUCCESS;
    }
}
