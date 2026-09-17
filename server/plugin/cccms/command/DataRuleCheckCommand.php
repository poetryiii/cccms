<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\app\model\DataRule;
use plugin\cccms\support\RuleConflict;
use plugin\cccms\support\SoftDelete;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use think\facade\Db;

/**
 * 数据权限规则体检。
 *
 * 最有价值的一类是「互相矛盾的行级规则」：多条命中同一批人的规则是 AND 叠加，
 * 两条语义相反的规则会把人过滤成「什么都看不到」—— fail-closed 但静默。
 * 本命令把它们报出来，便于上线前体检 / 接 CI。
 */
#[AsCommand('cccms:data-rule-check', '体检数据权限规则（条件互斥 / 同字段多动作 / 恒不生效 / 表未受控）')]
class DataRuleCheckCommand extends Command
{
    /** 类别 => 展示名 */
    private const LABELS = [
        RuleConflict::ROW_UNSAT   => '条件互斥：命中的用户会看不到任何数据',
        RuleConflict::FIELD_DUP   => '同字段多动作：按顺序叠加而不是取最严',
        RuleConflict::NEVER_HIT   => '恒不生效：取值不完整，规则被静默跳过',
        RuleConflict::NOT_GUARDED => '表未受控：规则不会执行',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rules = SoftDelete::apply(Db::name('data_rule'))->select()->toArray();
        if ($rules === []) {
            $output->writeln('<info>没有配置任何数据权限规则</info>');

            return Command::SUCCESS;
        }

        $findings = RuleConflict::check($rules);
        $output->writeln(sprintf('已配置规则 %d 条，体检结果 %d 项' . PHP_EOL, count($rules), count($findings)));

        $errors   = 0;
        $byType   = [];
        foreach ($findings as $finding) {
            $byType[$finding['type']][] = $finding;
        }

        foreach (self::LABELS as $type => $label) {
            $items = $byType[$type] ?? [];
            if ($items === []) {
                continue;
            }
            // 条件互斥会让人「什么都看不到」，属于必须处理的；其余是提示
            $fatal = $type === RuleConflict::ROW_UNSAT;
            $errors += $fatal ? count($items) : 0;

            $output->writeln(($fatal ? '<error>' : '<comment>') . "【{$label}】" . ($fatal ? '</error>' : '</comment>'));
            foreach ($items as $item) {
                $where = $item['table'] !== '' ? $item['table'] : '不限表';
                $output->writeln(sprintf(
                    '  - 规则 #%s（%s）%s',
                    implode(' / #', $item['rules']),
                    $where,
                    $item['message']
                ));
            }
            $output->writeln('');
        }

        if ($errors > 0) {
            $output->writeln("<error>存在 {$errors} 处条件互斥：这些组合会让用户看不到任何数据</error>");

            return Command::FAILURE;
        }

        $output->writeln('<info>规则体检通过</info>');

        return Command::SUCCESS;
    }
}
