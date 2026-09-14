<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\db\ConnectionInterface;
use think\facade\Db;

/**
 * think-orm 访问入口（薄封装，统一走 Facade）。
 *
 * 用法：
 *   Database::table('user')->where('id', 1)->find();
 *   Database::connect()->name('user')->select();
 */
final class Database
{
    /** 获取连接。 */
    public static function connect(string $name = 'mysql'): ConnectionInterface
    {
        return Db::connect($name);
    }

    /** 以表名（不含 sys_ 前缀）开始查询。 */
    public static function table(string $table)
    {
        return Db::name($table);
    }
}
