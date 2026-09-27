<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\LogVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 校验审计日志（`sys_log`）的链式哈希，定位被篡改或删除的位置。
 *
 * 用法：
 *   php webman cccms:log-verify                     # 从校验起点校验到链尾
 *   php webman cccms:log-verify --from=1000         # 只校验 id >= 1000
 *   php webman cccms:log-verify --from=1000 --to=2000
 *
 * 退出码：发现断点 → `1`；链自洽 → `0`（可直接接进巡检 / 告警脚本）。
 *
 * 判定口径与「哪些情形不算问题」见 `support/LogVerifier.php` 的类注释。
 */
#[AsCommand('cccms:log-verify', '校验审计日志链式哈希，定位被篡改/删除的位置')]
class LogVerifyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, '起始 id（含）；默认从链状态表的校验起点开始')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, '结束 id（含）；默认校验到链尾');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!LogVerifier::ready()) {
            $output->writeln(
                '<error>审计链尚未初始化</error>：请先执行 php webman cccms:db-upgrade'
                . '（补 sys_log.prev_hash / row_hash 列与 sys_log_chain 表）'
            );

            return Command::FAILURE;
        }

        $result = LogVerifier::verify(
            (int)($input->getOption('from') ?? 0),
            (int)($input->getOption('to') ?? 0)
        );

        $chain = $result['chain'];
        $output->writeln(sprintf(
            '校验区间 id %d ~ %d：实读 %d 条，其中未纳入链 %d 条',
            $result['from'],
            $result['to'] > 0 ? $result['to'] : (int)($chain['tail_id'] ?? 0),
            $result['checked'],
            $result['unchained']
        ));

        if ($chain !== null) {
            $output->writeln(sprintf(
                '链状态：起点 id=%d，链尾 id=%d，最后推进时间=%s',
                (int)($chain['anchor_id'] ?? 0),
                (int)($chain['tail_id'] ?? 0),
                (string)($chain['update_time'] ?? '-')
            ));
        }

        if ($result['ok']) {
            $output->writeln('<info>校验通过：链自洽，未发现篡改或断裂</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>发现 %d 处断点：</error>', $result['break_total']));
        foreach ($result['breaks'] as $break) {
            $output->writeln(sprintf('  id=%d [%s] %s', $break['id'], $break['reason'], $break['detail']));
        }
        if ($result['break_total'] > count($result['breaks'])) {
            $output->writeln(sprintf('  …… 另有 %d 处未列出', $result['break_total'] - count($result['breaks'])));
        }

        return Command::FAILURE;
    }
}
