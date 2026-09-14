<?php

declare(strict_types=1);

namespace plugin\cccms\support\attribute;

use Attribute;

/**
 * 登录即可，无需按钮权限（如个人资料）。
 *
 * 不是权限点（不产生按钮节点），但可以带一个 `$title` 作为**操作名**：
 * 操作日志按注解取可读名称，否则这类接口在日志里只会显示「—」。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class NoAuth
{
    public function __construct(public readonly ?string $title = null) {}
}
