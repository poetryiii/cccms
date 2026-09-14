<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use think\Model;

/** 模型基类。表名统一省略 sys_ 前缀（连接配置 prefix 自动补全）。 */
abstract class BaseModel extends Model
{
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
