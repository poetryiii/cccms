<?php

declare(strict_types=1);

namespace plugin\cccms\support\storage;

use plugin\cccms\support\SysConfig;

/** 依配置解析存储驱动。 */
final class StorageManager
{
    /** @var array<string,StorageDriver> */
    private static array $drivers = [];

    public static function driver(?string $name = null): StorageDriver
    {
        $name = $name ?: self::current();

        return self::$drivers[$name] ??= match ($name) {
            'oss'   => new OssDriver(),
            'cos'   => new CosDriver(),
            'qiniu' => new QiniuDriver(),
            default => new LocalDriver(),
        };
    }

    /** 当前驱动：优先取后台配置（upload.storage_driver），非法值回退 local */
    public static function current(): string
    {
        $name = SysConfig::getString('upload.storage_driver')
            ?: (string)config('plugin.cccms.filesystem.driver', 'local');

        return in_array($name, self::drivers(), true) ? $name : 'local';
    }

    /** @return string[] 已支持的驱动标识 */
    public static function drivers(): array
    {
        return ['local', 'oss', 'cos', 'qiniu'];
    }
}
