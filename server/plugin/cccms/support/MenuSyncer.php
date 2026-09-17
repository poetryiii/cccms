<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\Menu;
use think\facade\Db;

/**
 * 菜单同步：把 `db/menu.php` 的声明式目录/菜单写进 `sys_menu`。
 *
 * 抽成 support 是为了让 CLI（`cccms:menu-sync`）与后台「系统同步」接口共用同一份逻辑，
 * 避免出现「命令行跑一遍、界面跑一遍结果不一致」。
 *
 * 语义：
 *   - 按 `slug` 找行，找到就更新（parent_id / title / path / component / icon / sort），
 *     并**自动把回收站里的节点恢复**（声明式源文件仍是权威）；
 *   - 找不到就新建；
 *   - `['slug' => 'xxx', 'remove' => true]` 是废弃占位：删除该节点（含子树）与对应角色授权。
 */
final class MenuSyncer
{
    /** 声明式源文件路径 */
    public static function path(): string
    {
        return base_path() . '/plugin/cccms/db/menu.php';
    }

    /**
     * 所有插件的菜单声明文件（cccms 自身排最前，其余按路径排序）。
     *
     * 业务插件只要提供 `plugin/{插件}/db/menu.php`，即会被同步进 sys_menu，
     * 无需改动框架代码。
     *
     * @return array<int,string>
     */
    public static function menuFiles(): array
    {
        $files = glob(base_path() . '/plugin/*/db/menu.php') ?: [];
        $cccms = self::path();
        usort($files, static function (string $a, string $b) use ($cccms): int {
            if ($a === $cccms) {
                return -1;
            }
            if ($b === $cccms) {
                return 1;
            }
            return strcmp($a, $b);
        });
        return $files;
    }

    /**
     * 同步所有插件的菜单声明（cccms + 各业务插件）。
     *
     * @return array{created:int,updated:int,removed:int,perPlugin:array<string,array{created:int,updated:int,removed:int}>}
     */
    public static function syncAll(): array
    {
        $total = ['created' => 0, 'updated' => 0, 'removed' => 0, 'perPlugin' => []];

        foreach (self::menuFiles() as $file) {
            $plugin = self::pluginNameOf($file);
            $result = self::sync($file);

            $total['created'] += $result['created'];
            $total['updated'] += $result['updated'];
            $total['removed'] += $result['removed'];
            $total['perPlugin'][$plugin] = $result;
        }

        return $total;
    }

    /** 由菜单文件路径推断插件名（.../plugin/{插件}/db/menu.php → {插件}） */
    private static function pluginNameOf(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        if (preg_match('#/plugin/([^/]+)/db/menu\.php$#', $normalized, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }

    /**
     * @param  string|null $file 自定义源文件（测试用）
     * @return array{created:int,updated:int,removed:int}
     */
    public static function sync(?string $file = null): array
    {
        $file ??= self::path();
        if (!is_file($file)) {
            throw new ApiException('未找到 db/menu.php', 500);
        }

        $tree = (array)include $file;

        $created = 0;
        $updated = 0;
        $removed = 0;
        foreach ($tree as $node) {
            self::syncNode((array)$node, 0, $created, $updated, $removed);
        }

        return ['created' => $created, 'updated' => $updated, 'removed' => $removed];
    }

    /** @param array<string,mixed> $node */
    private static function syncNode(array $node, int $parentId, int &$created, int &$updated, int &$removed): void
    {
        // 废弃节点：删除该节点（含子节点）与角色授权
        if (!empty($node['remove'])) {
            self::removeNode((string)($node['slug'] ?? ''), $removed);

            return;
        }

        $slug = (string)($node['slug'] ?? '');
        if ($slug === '') {
            return;
        }

        // withTrashed()：下面要判断「是否躺在回收站里」并顺带恢复，
        // 模型默认排除已删数据，不加会永远查不到，恢复逻辑形同虚设
        $existing = Menu::withTrashed()->where('node', $slug)->find();

        $data = [
            'parent_id' => $parentId,
            'type'      => (int)($node['type'] ?? 1),
            'title'     => $node['title'] ?? '',
            'path'      => $node['path'] ?? '',
            'component' => $node['component'] ?? '',
            'icon'      => $node['icon'] ?? '',
            'sort'      => (int)($node['sort'] ?? 0),
            'status'    => 1,
        ];

        if ($existing) {
            // 声明式源文件里仍有该节点 → 若在回收站里则一并恢复
            if (!empty($existing->delete_time)) {
                $data['delete_time'] = null;
            }
            $existing->save($data);
            $id = (int)$existing->id;
            $updated++;
        } else {
            $menu = new Menu();
            $menu->save(array_merge($data, ['node' => $slug]));
            $id = (int)$menu->id;
            $created++;
        }

        foreach ($node['children'] ?? [] as $child) {
            self::syncNode((array)$child, $id, $created, $updated, $removed);
        }
    }

    /** 删除已废弃的节点（含其子节点）及其角色授权 */
    private static function removeNode(string $slug, int &$removed): void
    {
        if ($slug === '') {
            return;
        }
        $menu = Menu::where('node', $slug)->find();
        if (!$menu) {
            return;
        }

        self::purge((int)$menu->id, $removed);
    }

    private static function purge(int $id, int &$removed): void
    {
        foreach (Menu::where('parent_id', $id)->column('id') as $childId) {
            self::purge((int)$childId, $removed);
        }

        $node = (string)Menu::where('id', $id)->value('node');
        Menu::where('id', $id)->delete();
        if ($node !== '') {
            Db::name('role_node')->where('node', $node)->delete();
        }
        $removed++;
    }
}
