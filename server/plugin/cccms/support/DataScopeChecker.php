<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * 数据权限接入检查。
 *
 * 解决的问题：数据权限由**模型的全局作用域**提供，一旦「表登记了受控、查询却没走模型」，
 * 界面照常可用、规则照常保存，但实际一个条件都不会加 —— 属于静默失效，只能靠机器查。
 *
 * 两条规则：
 *   R1 控制器不得直连数据库（`Db::name` / `Db::table` / `Db::query`）：
 *      查询下沉到 Logic，控制器直连等于把数据权限关掉；
 *   R2 登记在 `sys_data_scope_table` 的表：
 *      - 代码里必须有对应模型，且不能声明 `$dataScope = false`（登记了受控却不参与 = 规则永远不生效）；
 *      - Logic 层不得再用 `Db::name('该表')` 查询（那条查询不受作用域约束）。
 *
 * 主代码里找不到模型的受控表只提示不报错：本仓库是**基础系统**，
 * 其它插件系统在自己部署里登记的业务表（如 `ks_*`）不归这里管。
 */
final class DataScopeChecker
{
    /** 控制器里禁止出现的数据库直连入口 */
    private const FORBIDDEN_IN_CONTROLLER = ['Db::name(', 'Db::table(', 'Db::query('];

    /**
     * @return array<int,array{level:string,file:string,line:int,msg:string}>
     */
    public static function check(): array
    {
        $findings = [];

        foreach (self::pluginDirs() as $dir) {
            self::checkControllers($dir, $findings);
        }

        self::checkGuardedTables(self::models(), $findings);

        return $findings;
    }

    // ------------------------------------------------------------------
    // R1：控制器不得直连数据库
    // ------------------------------------------------------------------

    /** @param array<int,array{level:string,file:string,line:int,msg:string}> $findings */
    private static function checkControllers(string $dir, array &$findings): void
    {
        foreach (self::phpFiles($dir . '/app/controller') as $file) {
            $content = (string)file_get_contents($file);
            foreach (self::FORBIDDEN_IN_CONTROLLER as $needle) {
                foreach (self::matchLines($content, '/' . preg_quote($needle, '/') . '/') as $line) {
                    $findings[] = [
                        'level' => 'error',
                        'file'  => self::rel($file),
                        'line'  => $line,
                        'msg'   => '控制器不得直连数据库（' . rtrim($needle, '(') . '）：查询请下沉到 Logic，否则绕过数据权限',
                    ];
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // R2：受控表必须模型化
    // ------------------------------------------------------------------

    /**
     * @param array<string,array{file:string,participates:bool}>            $models
     * @param array<int,array{level:string,file:string,line:int,msg:string}> $findings
     */
    private static function checkGuardedTables(array $models, array &$findings): void
    {
        try {
            $guarded = DataScope::guardedTables();
        } catch (Throwable $e) {
            $findings[] = [
                'level' => 'error',
                'file'  => '-',
                'line'  => 0,
                'msg'   => '读取受控表失败（数据库不可用？）：' . $e->getMessage(),
            ];

            return;
        }

        foreach ($guarded as $table) {
            if (!isset($models[$table])) {
                $findings[] = [
                    'level' => 'notice',
                    'file'  => '-',
                    'line'  => 0,
                    'msg'   => "受控表 {$table} 在主代码中找不到模型，已跳过（通常属于其它插件系统登记的表）",
                ];
                continue;
            }

            if (!$models[$table]['participates']) {
                $findings[] = [
                    'level' => 'error',
                    'file'  => $models[$table]['file'],
                    'line'  => self::lineOf($models[$table], '$dataScope'),
                    'msg'   => "受控表 {$table} 的模型声明了 \$dataScope = false：登记为受控却不参与数据权限，配好的规则永远不会生效",
                ];
            }
        }

        foreach (self::pluginDirs() as $dir) {
            foreach (self::phpFiles($dir . '/app/logic') as $file) {
                $content = (string)file_get_contents($file);
                foreach ($guarded as $table) {
                    // 没有模型的受控表不属于本系统职责，跳过
                    if (!isset($models[$table])) {
                        continue;
                    }
                    $pattern = '/Db::(?:name|table)\(\s*\'' . preg_quote($table, '/') . '\'\s*\)/';
                    foreach (self::matchLines($content, $pattern) as $line) {
                        $findings[] = [
                            'level' => 'error',
                            'file'  => self::rel($file),
                            'line'  => $line,
                            'msg'   => "受控表 {$table} 仍在 Logic 里用 Db::name 查询：该查询不经过模型作用域，不受数据权限约束",
                        ];
                    }
                }
            }
        }
    }

    /**
     * 扫描所有模型的表名与数据权限声明。
     *
     * @return array<string,array{file:string,participates:bool}>
     */
    private static function models(): array
    {
        $out = [];
        foreach (self::pluginDirs() as $dir) {
            foreach (self::phpFiles($dir . '/app/model') as $file) {
                $content = (string)file_get_contents($file);
                if (str_contains($content, 'abstract class')) {
                    continue; // BaseModel 这类基类
                }
                if (!preg_match('/protected\s+\$name\s*=\s*\'([^\']+)\'/', $content, $m)) {
                    continue;
                }
                $out[$m[1]] = [
                    'file'         => self::rel($file),
                    'participates' => !preg_match('/protected\s+\$dataScope\s*=\s*false\s*;/', $content),
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    /**
     * CCCMS 插件目录：与 PermScanner 口径一致 —— 声明了 `db/menu.php` 的才算业务插件。
     *
     * @return array<int,string>
     */
    private static function pluginDirs(): array
    {
        $out = [];
        foreach (glob(base_path() . '/plugin/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_dir($dir . '/app') || !is_file($dir . '/db/menu.php')) {
                continue;
            }
            $out[] = $dir;
        }
        sort($out);

        return $out;
    }

    /** @return array<int,string> */
    private static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * 正则命中的行号列表。
     *
     * @return array<int,int>
     */
    private static function matchLines(string $content, string $pattern): array
    {
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $lines = [];
        foreach ($matches[0] as $match) {
            $lines[] = substr_count($content, "\n", 0, (int)$match[1]) + 1;
        }

        return $lines;
    }

    /** @param array{file:string,participates:bool} $model */
    private static function lineOf(array $model, string $needle): int
    {
        $full = base_path() . '/../' . $model['file'];
        if (!is_file($full)) {
            return 0;
        }
        $lines = self::matchLines((string)file_get_contents($full), '/' . preg_quote($needle, '/') . '/');

        return $lines[0] ?? 0;
    }

    /** 转成相对项目根的路径，输出更短 */
    private static function rel(string $path): string
    {
        $root = str_replace('\\', '/', dirname(rtrim(base_path(), '/\\'))) . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
