<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/**
 * php-cs-fixer 配置（R-10）。
 *
 * 以 `@PSR12` 为基线，只保留与**现有代码风格一致**的少量补充规则：
 *   - `array_syntax` / `single_quote` / `concat_space`：与仓库既有写法一致（短数组、单引号、点两侧空格）；
 *   - `ordered_imports` / `no_unused_imports`：清理 `use` 段（只增不删的历史包袱靠它收敛）；
 *   - `trailing_comma_in_multiline`：多行数组末尾逗号（便于后续 diff 只增一行）。
 *
 * **刻意不启用**的规则（会与现有约定冲突或制造大范围无意义 diff）：
 *   - `declare_strict_types`：本仓库在插件文件里统一手写 `declare(strict_types=1)`，
 *     由人决定而不是自动加；
 *   - `phpdoc_align` / `blank_line_before_statement`：注释与空行排版保持人工判断，
 *     自动对齐会让中文注释的可读性下降、diff 噪音变大。
 *
 * 用法：
 *   composer cs:check   # 只检查（CI 用，等价 --dry-run --diff）
 *   composer cs:fix     # 就地格式化
 */
/*
 * 校验范围**只覆盖本仓库维护的代码**：`plugin/`（框架核心插件 + 业务插件）与 `tests/`。
 *
 * 刻意不含 `server/config` / `server/support` / `server/app` / `server/start.php`
 * 这些 **Webman 模板自带**的文件：它们由上游模板提供，格式化会在
 * `cccms:update` 的三方哈希比对里变成「本地已改」而被永久跳过，反而丢掉框架更新。
 */
$finder = Finder::create()
    ->in(__DIR__ . '/plugin')
    ->in(__DIR__ . '/tests')
    ->exclude([
        'vendor',
        'runtime',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setFinder($finder)
    // 缓存写进 runtime（已被 .gitignore 忽略），避免在仓库根留下 .php-cs-fixer.cache
    ->setCacheFile(__DIR__ . '/runtime/.php-cs-fixer.cache')
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12'                        => true,
        'array_syntax'                  => ['syntax' => 'short'],
        'single_quote'                  => true,
        'concat_space'                  => ['spacing' => 'one'],
        'ordered_imports'               => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'             => true,
        'no_trailing_comma_in_singleline' => true,
        'trailing_comma_in_multiline'   => ['elements' => ['arrays']],
        'blank_line_after_opening_tag'  => true,
        'no_blank_lines_after_class_opening' => true,
    ]);
