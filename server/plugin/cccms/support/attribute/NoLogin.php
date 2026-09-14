<?php

declare(strict_types=1);

namespace plugin\cccms\support\attribute;

use Attribute;

/**
 * 完全匿名（登录页、验证码）。类级生效于整个控制器。
 *
 * 不是权限点（不产生按钮节点），但可以带一个 `$title` 作为**操作名**：
 * 操作日志按注解取可读名称，否则这类接口在日志里只会显示「—」。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NoLogin
{
    public function __construct(public readonly ?string $title = null) {}
}
