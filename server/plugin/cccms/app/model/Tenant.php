<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

/**
 * 租户（sys_tenant）。
 *
 * 只存**真实租户**（id >= 1）：平台租户（0）是虚拟的，不占行 —— 见 `TenantContext::PLATFORM_ID`。
 *
 * 本表是**平台级元数据**，刻意不参与任何隔离：
 *   - `$tenantScope` 保持默认 false —— 否则超管切进某个租户后就看不到别的租户了；
 *   - `$dataScope = false` —— 租户名单不属于任何用户的「数据范围」。
 */
class Tenant extends BaseModel
{
    protected $name = 'tenant';

    /** 平台级元数据：不参与数据权限 */
    protected $dataScope = false;
}