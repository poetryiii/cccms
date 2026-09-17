<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

class UserPost extends BaseModel
{
    protected $name = 'user_post';

    /** 关联表没有时间戳列 */
    protected $autoWriteTimestamp = false;

    /** 关联表不参与数据权限（它本身就是范围计算的输入） */
    protected $dataScope = false;

    /** 没有 delete_time 列 */
    protected $deleteTime = false;
}
