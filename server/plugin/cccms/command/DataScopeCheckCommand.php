<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\DataScopeChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cccms:data-scope-check', '校验数据权限接入（控制器直连库 / 受控表未模型化）')]
class DataScopeCheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findings = DataScopeChecker::check();
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
            $output->writeln("<error>数据权限接入检查未通过：{$errors} 处错误</error>");

            return Command::FAILURE;
        }

        $output->writeln('<info>数据权限接入检查通过</info>');

        return Command::SUCCESS;
    }
}
