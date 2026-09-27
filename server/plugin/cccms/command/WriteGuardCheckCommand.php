<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\WriteGuardChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cccms:write-guard-check', '校验写入口是否声明字段白名单（防 Mass Assignment）')]
class WriteGuardCheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findings = WriteGuardChecker::check();
        $errors   = 0;

        foreach ($findings as $finding) {
            $where = $finding['file'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '');
            $line  = '[' . strtoupper($finding['level']) . "] {$where} {$finding['msg']}";

            if ($finding['level'] === 'error') {
                $errors++;
                $output->writeln("<error>{$line}</error>");
            } else {
                $output->writeln("<comment>{$line}</comment>");
            }
        }

        if ($errors > 0) {
            $output->writeln("<error>写入口白名单检查未通过：{$errors} 处错误</error>");

            return Command::FAILURE;
        }

        $output->writeln('<info>写入口白名单检查通过</info>');

        return Command::SUCCESS;
    }
}
