<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

/**
 * 通知公告。
 *
 * 数据权限声明为**不参与**：公告是面向所有登录用户的广播内容，没有「归属人」语义，
 * 按 creator 收窄会让被授权的人看不到别人发布的公告。
 * 管理侧由权限节点 `cccms:notice:*` 控制，读取侧对所有登录用户开放（只看已发布的）。
 */
class Notice extends BaseModel
{
    protected $name = 'notice';

    /**
     * 参与多租户隔离：公告只在本租户内广播。
     *
     * 与 `$dataScope = false`（不按人收窄）**不冲突**：租户是硬边界、档位是租户内软范围，
     * 「面向全租户广播」说的正是「不按人收窄，但仍不出这个租户」。
     */
    protected $tenantScope = true;

    protected $dataScope = false;
}
