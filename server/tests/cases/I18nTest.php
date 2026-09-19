<?php

declare(strict_types=1);

use plugin\cccms\support\I18n;

/**
 * 国际化语言包体检。
 *
 * 这里校验的是「语言包自身的一致性」，而不是某个接口的输出：
 *   - I18n::has() 的命中/未命中语义（菜单标题、配置项名依赖它做「有翻译才覆盖」）
 *   - 各语言包的 key 集合必须与 zh-CN 完全一致（防止英文包漏词后界面直接暴露 key）
 *   - 所有权限节点 slug 都要有 `menu.{slug}` 标题（新加控制器注解时最容易漏）
 *
 * 语言包是纯 PHP 数组，不依赖数据库，因此无需 requiresDb。
 */
return function (): void {
    $pluginDir = dirname(__DIR__, 2) . '/plugin/cccms';
    $langDir   = $pluginDir . '/lang';

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

    test('各语言包 key 集合与 zh-CN 完全一致', function () use ($langDir): void {
        $namespaces = [];
        foreach (glob($langDir . '/*/*.php') ?: [] as $file) {
            $namespaces[basename($file)] = true;
        }
        ok($namespaces !== [], '未找到任何语言包文件');

        $diffs = [];
        foreach (array_keys($namespaces) as $namespace) {
            $base = require $langDir . '/zh-CN/' . $namespace;
            $base = is_array($base) ? array_keys($base) : [];

            foreach (glob($langDir . '/*/' . $namespace) ?: [] as $file) {
                $locale = basename(dirname($file));
                $other  = require $file;
                $other  = is_array($other) ? array_keys($other) : [];

                foreach (array_diff($base, $other) as $key) {
                    $diffs[] = "{$locale}/{$namespace} 缺 {$key}";
                }
                foreach (array_diff($other, $base) as $key) {
                    $diffs[] = "zh-CN/{$namespace} 缺 {$key}（{$locale} 独有）";
                }
            }
        }

        same([], $diffs, '语言包 key 集合不一致：' . implode('；', array_slice($diffs, 0, 10)));
    });

    test('权限节点 slug 均有 menu 标题翻译', function () use ($pluginDir): void {
        $slugs = [];

        // ① 声明式菜单 / 目录（db/menu.php 是嵌套结构，用正则抓 slug 比 require 后递归更稳）。
        //    先剥掉注释，避免把「删除节点」示例里的 'xxx' 当成真实 slug；
        //    再跳过 'remove' => true 的墓碑条目 —— 那些节点已被 menu-sync 删除，不需要标题。
        $menuSeed = file_get_contents($pluginDir . '/db/menu.php') ?: '';
        $menuSeed = preg_replace('#/\*.*?\*/#s', '', $menuSeed) ?? $menuSeed;
        $menuSeed = preg_replace('#^[ \t]*//.*$#m', '', $menuSeed) ?? $menuSeed;

        $parts = preg_split("/'slug'\s*=>\s*'/", $menuSeed) ?: [];
        array_shift($parts); // 第一段是 slug 声明之前的内容
        foreach ($parts as $part) {
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

        // ② 控制器注解里的按钮节点
        foreach (glob($pluginDir . '/app/controller/*.php') ?: [] as $file) {
            $source = file_get_contents($file) ?: '';
            if (preg_match_all("/#\[Permission\(slug:\s*'([^']+)'/", $source, $m)) {
                $slugs = array_merge($slugs, $m[1]);
            }
        }

        $slugs = array_values(array_unique($slugs));
        ok(count($slugs) > 80, '收集到的 slug 数量异常，正则可能失效：' . count($slugs));

        $missing = array_values(array_filter($slugs, static fn (string $slug): bool => !I18n::has('menu.' . $slug)));
        same([], $missing, '以下 slug 缺少 menu 标题翻译：' . implode(', ', array_slice($missing, 0, 10)));
    });
};