<?php

declare(strict_types=1);

/**
 * CCCMS 后端测试运行器（零依赖）。
 *
 * 没有引入 PHPUnit：本项目的测试对象是**纯逻辑与少量需要数据库的判定**
 * （Cron 匹配、权限节点规范、CSV、数据规则体检），一个 200 行的运行器足够，
 * 也避免 CI 里多一层依赖。需要 PHPUnit 的高级特性（mock / dataProvider）时再迁移。
 *
 * 用法：
 *   php tests/run.php            # 全部
 *   php tests/run.php Cron       # 只跑名字包含 Cron 的用例
 *
 * 需要数据库的用例在数据库不可用时**自动跳过**（本地无库也能跑）。
 * 退出码：有失败 → 1，否则 0（可直接用于 CI）。
 */

require __DIR__ . '/../vendor/autoload.php';

use think\facade\Db;

final class Suite
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var array<int,string> */
    public static array $failures = [];
    public static string $group = '';
    /** @var string|null 过滤器（命令行参数） */
    public static ?string $filter = null;
    private static ?bool $dbReady = null;

    public static function dbAvailable(): bool
    {
        if (self::$dbReady !== null) {
            return self::$dbReady;
        }

        try {
            Db::query('SELECT 1');
            self::$dbReady = true;
        } catch (Throwable) {
            self::$dbReady = false;
        }

        return self::$dbReady;
    }

    public static function color(string $text, string $color): string
    {
        // Windows 老终端不支持 ANSI，简单探测后回退为无色
        if (DIRECTORY_SEPARATOR === '\\' && getenv('ANSICON') === false && getenv('WT_SESSION') === false) {
            return $text;
        }

        $map = ['green' => '32', 'red' => '31', 'yellow' => '33', 'cyan' => '36', 'gray' => '90'];

        return "\033[" . ($map[$color] ?? '0') . 'm' . $text . "\033[0m";
    }
}

function suite(string $name): void
{
    Suite::$group = $name;
    echo "\n" . Suite::color('■ ' . $name, 'cyan') . "\n";
}

/**
 * 注册一个用例。
 *
 * @param callable():void $fn
 * @param bool            $requiresDb 需要数据库；不可用时跳过
 */
function test(string $name, callable $fn, bool $requiresDb = false): void
{
    if (Suite::$filter !== null && stripos($name, Suite::$filter) === false && stripos(Suite::$group, Suite::$filter) === false) {
        return;
    }

    if ($requiresDb && !Suite::dbAvailable()) {
        Suite::$skipped++;
        echo '  ' . Suite::color('○ 跳过', 'gray') . " {$name}（数据库不可用）\n";
        return;
    }

    try {
        $fn();
        Suite::$passed++;
        echo '  ' . Suite::color('✓', 'green') . " {$name}\n";
    } catch (Throwable $e) {
        Suite::$failed++;
        Suite::$failures[] = "[{$name}] " . $e->getMessage();
        echo '  ' . Suite::color('✗', 'red') . " {$name}\n";
        echo '      ' . Suite::color($e->getMessage(), 'red') . "\n";
    }
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function ok(bool $condition, string $message = '断言失败'): void
{
    if (!$condition) {
        fail($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ' — ' : '')
            . '期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ' — ' : '') . '未找到 ' . var_export($needle, true));
    }
}

// ---------------------------------------------------------------------
// 载入用例文件（每个文件返回一个注册闭包）
// ---------------------------------------------------------------------
$filter = $argv[1] ?? null;
Suite::$filter = $filter !== null && $filter !== '' ? $filter : null;

$files = glob(__DIR__ . '/cases/*.php') ?: [];
sort($files);

foreach ($files as $file) {
    $register = require $file;
    if (is_callable($register)) {
        $register();
    }
}

echo "\n" . str_repeat('-', 56) . "\n";
echo sprintf(
    "通过 %s，失败 %s，跳过 %s%s\n",
    Suite::color((string)Suite::$passed, 'green'),
    Suite::$failed > 0 ? Suite::color((string)Suite::$failed, 'red') : '0',
    (string)Suite::$skipped,
    $filter ? "（过滤：{$filter}）" : ''
);

if (Suite::$failed > 0) {
    echo "\n" . Suite::color('失败详情：', 'red') . "\n";
    foreach (Suite::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit(1);
}

exit(0);
