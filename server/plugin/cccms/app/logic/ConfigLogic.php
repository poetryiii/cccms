<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\SessionGuard;
use plugin\cccms\support\SysConfig;
use think\facade\Db;

/** 系统配置逻辑。 */
final class ConfigLogic
{
    /** 配置列表（按分组、排序输出；group 为空则返回全部） */
    public static function list(string $group = ''): array
    {
        $query = Db::name('config')->where('status', 1);
        if ($group !== '') {
            $query->where('group', $group);
        }

        $rows = $query->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        // 这里走的是查询构造器而非模型，json 字段不会自动解码，手工转一次
        foreach ($rows as &$row) {
            $options = $row['options'] ?? null;
            if (is_string($options)) {
                $decoded         = json_decode($options, true);
                $row['options'] = is_array($decoded) ? $decoded : null;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * 登录页 / 前端初始化需要的公开配置。
     *
     * 这是**白名单**：只有品牌信息与 UI 默认值，不含安全、上传等敏感项。
     * 登录页在拿到 token 之前就要用系统名称 / Logo / 主题色，所以走 #[NoLogin]。
     *
     * @return array{system:array<string,mixed>,ui:array<string,mixed>}
     */
    public static function ui(): array
    {
        return [
            'system' => [
                'name'        => SysConfig::getString('system.name', 'CCCMS'),
                'logo'        => SysConfig::getString('system.logo'),
                'icp'         => SysConfig::getString('system.icp'),
                'copyright'   => SysConfig::getString('system.copyright'),
                'maintenance' => SysConfig::getBool('system.maintenance'),
                'notice'      => SysConfig::getString('system.maintenance_notice'),
            ],
            'ui' => [
                'theme_mode'      => SysConfig::getString('ui.theme_mode', 'light'),
                'theme_primary'   => SysConfig::getString('ui.theme_primary', '#2b6cff'),
                'page_size'       => SysConfig::getInt('ui.page_size', 15),
                'tags_view'       => SysConfig::getBool('ui.tags_view', true),
                'container_width' => SysConfig::getInt('ui.container_width', 0),
            ],
        ];
    }

    /**
     * 按 name 批量保存配置值。
     *
     * 只更新**已存在**的配置项：请求体里的未知键一律忽略，
     * 避免前端传参出错或恶意请求往 sys_config 里塞脏数据。
     *
     * @param array<string,mixed> $values
     * @return int 实际更新的配置项数量
     */
    public static function save(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $names = Db::name('config')->whereIn('name', array_keys($values))->column('name');
        if ($names === []) {
            return 0;
        }

        $now     = date('Y-m-d H:i:s');
        $updated = 0;

        Db::transaction(static function () use ($values, $names, $now, &$updated): void {
            foreach ($values as $name => $value) {
                if (!in_array((string)$name, $names, true)) {
                    continue;
                }
                Db::name('config')->where('name', $name)->update([
                    'value'       => self::encode($value),
                    'update_time' => $now,
                ]);
                $updated++;
            }
        });

        // 让本次及各进程尽快看到新配置（清 Redis 缓存 + 本进程 memo）
        if ($updated > 0) {
            SysConfig::flush();
        }

        // 开启维护模式：立刻作废此前签发的令牌（超管豁免），
        // 已登录的普通用户下一次请求就会 401 被退回登录页，无需等令牌过期。
        if (array_key_exists('system.maintenance', $values) && self::truthy($values['system.maintenance'])) {
            SessionGuard::cut();
        }

        return $updated;
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes'], true);
    }

    /** 配置值统一按字符串入库，非标量（数组等）转 JSON */
    private static function encode(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
