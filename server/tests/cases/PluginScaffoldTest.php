<?php

declare(strict_types=1);

use plugin\cccms\support\PermScanner;
use plugin\cccms\support\PluginScaffolder;

return static function (): void {
    suite('插件脚手架（P2-18）');

    test('插件名校验：合法 / 非法 / 保留名', function (): void {
        same('shop', PluginScaffolder::normalizeName(' Shop '), '应 trim + 转小写');
        same('shopx', PluginScaffolder::normalizeName('ShopX'), '大小写混写应被规范化为小写');

        foreach (['', '1shop', 'shop-x', 'shop.x', 'shop x'] as $bad) {
            $thrown = false;
            try {
                PluginScaffolder::normalizeName($bad);
            } catch (Throwable) {
                $thrown = true;
            }
            ok($thrown, "应拒绝非法插件名：{$bad}");
        }

        foreach (['cccms', 'index'] as $reserved) {
            $thrown = false;
            try {
                PluginScaffolder::normalizeName($reserved);
            } catch (Throwable) {
                $thrown = true;
            }
            ok($thrown, "应拒绝内置（保留）插件名：{$reserved}");
        }
    });

    test('后台骨架：文件齐全、注解 slug 合法、菜单归属正确', function (): void {
        $plan  = PluginScaffolder::plan([
            'name'         => 'shop',
            'title'        => '商城',
            'module'       => 'goods',
            'module_title' => '商品',
        ]);
        $paths = array_keys($plan);

        foreach ([
            'server/plugin/shop/config/app.php',
            'server/plugin/shop/config/exception.php',
            'server/plugin/shop/config/middleware.php',
            'server/plugin/shop/config/route.php',
            'server/plugin/shop/app/controller/GoodsController.php',
            'server/plugin/shop/app/logic/GoodsLogic.php',
            'server/plugin/shop/db/menu.php',
        ] as $path) {
            ok(in_array($path, $paths, true), "缺少文件：{$path}");
        }

        $controller = $plan['server/plugin/shop/app/controller/GoodsController.php'];
        contains('namespace plugin\\shop\\app\\controller;', $controller);
        contains("slug: 'shop:goods:index'", $controller);
        foreach (['Db::name(', 'Db::table(', 'Db::query('] as $forbidden) {
            ok(!str_contains($controller, $forbidden), "示例控制器不得直连数据库（data-scope-check R1）：{$forbidden}");
        }

        // perm-scan 用一条正则校验 slug 格式，这里必须与它一致
        preg_match_all("/slug: '([^']+)'/", $controller, $m);
        ok(count($m[1]) >= 3, '示例控制器应声明至少 3 个权限节点');
        foreach ($m[1] as $slug) {
            ok(preg_match(PermScanner::SLUG_PATTERN, $slug) === 1, "注解 slug 不合法：{$slug}");
        }

        $menu = $plan['server/plugin/shop/db/menu.php'];
        contains("'slug'      => 'shop'", $menu);
        contains("'slug'      => 'shop:goods'", $menu);
        contains("'component' => 'shop/goods/index'", $menu);
    });

    test('前台骨架：不产菜单 / Logic，路由公开', function (): void {
        $plan = PluginScaffolder::plan(['name' => 'site', 'type' => 'frontend', 'module' => 'home']);

        ok(!isset($plan['server/plugin/site/db/menu.php']), '前台插件不应生成 db/menu.php');
        ok(!isset($plan['server/plugin/site/app/logic/HomeLogic.php']), '前台插件不应生成 Logic');
        contains('/site/ping', $plan['server/plugin/site/config/route.php']);
        contains('Cors::class', $plan['server/plugin/site/config/middleware.php']);
        contains('HomeController', $plan['server/plugin/site/config/route.php']);
    });

    test('落盘骨架语法正确（php -l）且拒绝覆盖已存在目录', function (): void {
        if (!function_exists('exec')) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " 语法检查（exec 被禁用）\n";
            return;
        }

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cccms-scaffold-' . bin2hex(random_bytes(4));

        $rm = static function (string $dir) use (&$rm): void {
            if (!is_dir($dir)) {
                return;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $entry;
                is_dir($path) ? $rm($path) : @unlink($path);
            }
            @rmdir($dir);
        };

        try {
            $files = PluginScaffolder::create([
                'name'         => 'scafftest',
                'title'        => '脚手架测试',
                'module'       => 'item',
                'module_title' => '条目',
            ], $root);

            same(7, count($files), '后台骨架应写入 7 个文件');

            foreach ($files as $rel) {
                $full = $root . '/' . $rel;
                ok(is_file($full), "文件未写入：{$rel}");

                $out  = [];
                $code = -1;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($full) . ' 2>&1', $out, $code);
                same(0, $code, "生成文件语法错误：{$rel} → " . implode(' / ', $out));
            }

            $thrown = false;
            try {
                PluginScaffolder::create(['name' => 'scafftest'], $root);
            } catch (Throwable) {
                $thrown = true;
            }
            ok($thrown, '目录已存在且未加 force 时应拒绝覆盖');

            // force 覆盖：应能成功重写
            $again = PluginScaffolder::create(['name' => 'scafftest', 'force' => true], $root);
            ok(count($again) === 7, 'force 覆盖应重写全部文件');
        } finally {
            $rm($root);
        }
    });
};
