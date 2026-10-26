<?php

declare(strict_types=1);

use plugin\cccms\support\I18n;

/**
 * 国际化语言包体检。
 *
 * 这里校验的是「语言包自身的一致性」，而不是某个接口的输出：
 *   - I18n::has() 的命中/未命中语义（菜单标题、配置项名依赖它做「有翻译才覆盖」）
 *   - **每个插件**的语言包 key 集合必须与自己的 zh-CN 完全一致（防止英文包漏词后界面直接暴露 key）
 *   - 插件语言包的 key 必须真能被 I18n 读到（`menu` / `apidoc` 这类命名空间 cccms 自己也有一份，
 *     只取第一个文件的话新插件的标题永远是中文或 key 原文）
 *   - 所有带 `db/menu.php` 的业务插件，其权限节点 slug 都要有 `menu.{slug}` 标题
 *     （新加控制器注解 / 新插件最容易漏）
 *
 * 语言包是纯 PHP 数组，不依赖数据库，因此无需 requiresDb。
 */
return function (): void {
    $pluginRoot = dirname(__DIR__, 2) . '/plugin';

    suite('国际化');

    test('I18n::has() 命中已登记 key，未命中原样返回', function (): void {
        ok(I18n::has('auth.login_success'), 'auth.login_success 应已登记');
        ok(I18n::has('common.created'), 'common.created 应已登记');
        ok(!I18n::has('auth.__not_exist__'), '未登记的 key 不应命中');
        ok(!I18n::has('__no_such_namespace__.foo'), '不存在的命名空间不应命中');

        // 未命中时 has() 必须返回 false 且**不抛异常** —— 它刻意不写缺失日志，
        // 因为「查不到」在菜单/配置项场景是正常分支而不是缺词。
        same(false, I18n::has('menu.this-slug-does-not-exist'), '未知菜单 slug 不应命中');
    });

    test('各插件语言包 key 集合与自己的 zh-CN 完全一致', function () use ($pluginRoot): void {
        $langDirs = glob($pluginRoot . '/*/lang', GLOB_ONLYDIR) ?: [];
        ok($langDirs !== [], '未找到任何插件语言包目录');

        $diffs = [];
        foreach ($langDirs as $langDir) {
            $plugin = basename(dirname($langDir));

            // 命名空间取所有语言目录的并集：只在 en-US 里出现的文件同样是问题
            $namespaces = [];
            foreach (glob($langDir . '/*/*.php') ?: [] as $file) {
                $namespaces[basename($file)] = true;
            }

            foreach (array_keys($namespaces) as $file) {
                $namespace = basename($file, '.php');
                $baseFile  = $langDir . '/zh-CN/' . $file;
                if (!is_file($baseFile)) {
                    $diffs[] = "{$plugin}: 缺少 zh-CN/{$namespace}";
                    continue;
                }
                $base     = (array)require $baseFile;
                $baseKeys = array_keys($base);

                foreach (glob($langDir . '/*/' . $namespace . '.php') ?: [] as $otherFile) {
                    $locale = basename(dirname($otherFile));
                    $other  = array_keys((array)require $otherFile);

                    foreach (array_diff($baseKeys, $other) as $key) {
                        $diffs[] = "{$plugin}: {$locale}/{$namespace} 缺 {$key}";
                    }
                    foreach (array_diff($other, $baseKeys) as $key) {
                        $diffs[] = "{$plugin}: zh-CN/{$namespace} 缺 {$key}（{$locale} 独有）";
                    }
                }

                // 合并读取：插件自建的同名命名空间（menu / apidoc）必须真的能被 I18n 命中，
                // 否则文档标题、菜单标题会静默回落到中文或 key 原文。
                foreach ($baseKeys as $key) {
                    if (!I18n::has($namespace . '.' . $key)) {
                        $diffs[] = "{$plugin}: I18n 读不到 {$namespace}.{$key}（被同名命名空间挡住？）";
                    }
                }
            }
        }

        same([], $diffs, '语言包 key 集合不一致：' . implode('；', array_slice($diffs, 0, 10)));
    });

    test('权限节点 slug 均有 menu 标题翻译（所有业务插件）', function () use ($pluginRoot): void {
        /**
         * 声明式菜单 / 目录（db/menu.php 是嵌套结构，用正则抓 slug 比 require 后递归更稳）。
         * 先剥掉注释，避免把「删除节点」示例里的 'xxx' 当成真实 slug；
         * 再跳过 'remove' => true 的墓碑条目 —— 那些节点已被 menu-sync 删除，不需要标题。
         *
         * @return array<int,string>
         */
        $menuSlugs = static function (string $menuFile): array {
            $seed = file_get_contents($menuFile) ?: '';
            $seed = preg_replace('#/\*.*?\*/#s', '', $seed) ?? $seed;
            $seed = preg_replace('#^[ \t]*//.*$#m', '', $seed) ?? $seed;

            $slugs = [];
            foreach (preg_split("/'slug'\s*=>\s*'/", $seed) ?: [] as $index => $part) {
                if ($index === 0) {
                    continue; // 第一段是 slug 声明之前的内容
                }
                $slug = strstr($part, "'", true);
                if ($slug === false || $slug === '') {
                    continue;
                }
                // 截到当前数组元素的闭合括号，只有这一段里出现 remove 才算墓碑
                $element = strstr($part, ']', true) ?: $part;
                if (str_contains($element, "'remove'")) {
                    continue;
                }
                $slugs[] = $slug;
            }

            return $slugs;
        };

        $total  = 0;
        $groups = [];
        foreach (glob($pluginRoot . '/*/db/menu.php') ?: [] as $menuFile) {
            $pluginDir = dirname(dirname($menuFile));
            $plugin    = basename($pluginDir);

            $slugs = $menuSlugs($menuFile);

            // 控制器注解里的按钮节点
            foreach (glob($pluginDir . '/app/controller/*.php') ?: [] as $file) {
                $source = file_get_contents($file) ?: '';
                if (preg_match_all("/#\[Permission\(slug:\s*'([^']+)'/", $source, $m)) {
                    $slugs = array_merge($slugs, $m[1]);
                }
            }

            $slugs = array_values(array_unique($slugs));
            $total += count($slugs);

            $missing = array_values(array_filter($slugs, static fn (string $slug): bool => !I18n::has('menu.' . $slug)));
            if ($missing !== []) {
                $groups[] = $plugin . '：' . implode(', ', array_slice($missing, 0, 10));
            }
        }

        ok($total > 80, '收集到的 slug 数量异常，正则可能失效：' . $total);
        same([], $groups, '以下插件缺少 menu 标题翻译：' . implode('；', $groups));
    });
};
