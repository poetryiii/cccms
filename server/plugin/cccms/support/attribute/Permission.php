<?php

declare(strict_types=1);

namespace plugin\cccms\support\attribute;

use Attribute;

/**
 * 业务接口：需登录 + 需权限。
 *
 * slug 格式：{模块}:{资源}:{动作}，至少 3 段，全小写。
 * 最后一段为动作，其之前的所有段拼起来为归属菜单的 slug。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Permission
{
    public function __construct(
        public readonly string  $slug,
        public readonly string  $title,
        public readonly int     $sort  = 0,
        public readonly ?string $group = null,
    ) {}
}
