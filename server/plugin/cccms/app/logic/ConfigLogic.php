<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\Cipher;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PasswordReset;
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

            // 敏感项不下发密文：只回传「是否已配置」，编辑时留空即不修改
            if ((string)($row['type'] ?? '') === 'password') {
                $row['has_value'] = (string)($row['value'] ?? '') !== '';
                $row['value']     = '';
            }

            self::localize($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * 配置项的展示文案国际化。
     *
     * - `title`：内置项在 `lang/{locale}/config_item.php` 里有 `config_item.{name}` → 覆盖；
     *   管理员自建项（用户录入数据）没有 key → 保留 DB 里的原文。
     * - `group_label`：新增字段承载翻译后的分组名；`group` 原样保留，
     *   因为前端用它做 `?group=` 过滤值，改掉会破坏过滤。
     * - `options[].label`：选项标签同理按 `config_item.option.{value}` 覆盖。
     */
    private static function localize(array &$row): void
    {
        $name = (string)($row['name'] ?? '');
        if ($name !== '' && I18n::has('config_item.' . $name)) {
            $row['title'] = I18n::t('config_item.' . $name);
        }

        $group = (string)($row['group'] ?? '');
        $row['group_label'] = ($group !== '' && I18n::has('config_item.group.' . $group))
            ? I18n::t('config_item.group.' . $group)
            : $group;

        if (!is_array($row['options'] ?? null)) {
            return;
        }

        foreach ($row['options'] as &$option) {
            if (!is_array($option)) {
                continue;
            }
            $value = (string)($option['value'] ?? '');
            if ($value !== '' && I18n::has('config_item.option.' . $value)) {
                $option['label'] = I18n::t('config_item.option.' . $value);
            }
        }
        unset($option);
    }

    /**
     * 登录页 / 前端初始化需要的公开配置。
     *
     * 这是**白名单**：只有品牌信息、UI 默认值与找回密码渠道开关，不含上传等敏感项。
     * 登录页在拿到 token 之前就要用系统名称 / Logo / 主题色，所以走 #[NoLogin]。
     *
     * @return array{system:array<string,mixed>,ui:array<string,mixed>,security:array<string,mixed>}
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
            // 找回密码可用渠道（空数组 = 关闭）：登录页据此决定是否展示「忘记密码」入口。
            // 只下发渠道名，不下发任何密钥类配置。
            'security' => [
                'reset_channels' => PasswordReset::channels(),
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

        // name => type（type=password 的敏感项需要加密存储）
        $types = Db::name('config')->whereIn('name', array_keys($values))->column('type', 'name');
        if ($types === []) {
            return 0;
        }

        $now     = date('Y-m-d H:i:s');
        $updated = 0;

        Db::transaction(static function () use ($values, $types, $now, &$updated): void {
            foreach ($values as $name => $value) {
                $name = (string)$name;
                if (!isset($types[$name])) {
                    continue; // 未知键一律忽略，避免往 sys_config 塞脏数据
                }

                if ((string)$types[$name] === 'password') {
                    if ((string)$value === '') {
                        continue; // 敏感项留空 = 保持原值不变
                    }
                    $stored = SysConfig::SECRET_PREFIX . Cipher::encrypt((string)$value);
                } else {
                    $stored = self::encode($value);
                }

                Db::name('config')->where('name', $name)->update([
                    'value'       => $stored,
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
