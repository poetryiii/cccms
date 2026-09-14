<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\support\storage\StorageManager;
use Webman\Http\UploadFile;

/**
 * 附件存储门面：委托给当前配置的驱动（local / oss / cos / qiniu）。
 *
 * 注意：`url()` 使用**当前**驱动生成，切换存储后端后历史数据需迁移（见 README）。
 */
final class FileStorage
{
    /**
     * @return array{path:string,name:string,original_name:string,url:string,size:int,mime:string,ext:string,hash:string}
     */
    public static function upload(UploadFile $file): array
    {
        return StorageManager::driver()->upload($file);
    }

    public static function delete(string $path): void
    {
        StorageManager::driver()->delete($path);
    }

    public static function url(string $path): string
    {
        return StorageManager::driver()->url($path);
    }

    /** 当前生效的驱动名（来自 upload.storage_driver） */
    public static function driver(): string
    {
        return StorageManager::current();
    }

    /** 该扩展名是否属于图片（upload.image_ext），供前端决定是否显示缩略图 */
    public static function isImage(string $ext): bool
    {
        $imageExt = SysConfig::getList('upload.image_ext');
        if ($imageExt === []) {
            $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        }
        return in_array(strtolower($ext), $imageExt, true);
    }
}
