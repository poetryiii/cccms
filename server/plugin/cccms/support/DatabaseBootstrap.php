<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Webman\Bootstrap;

/** 启动时把 think-orm 配置注入 Facade，供模型与查询使用。 */
class DatabaseBootstrap implements Bootstrap
{
    public static function start($worker): void
    {
        Db::setConfig((array)config('plugin.cccms.database', []));
    }
}
