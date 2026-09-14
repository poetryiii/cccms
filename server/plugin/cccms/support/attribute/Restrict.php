<?php

declare(strict_types=1);

namespace plugin\cccms\support\attribute;

use Attribute;

/** 可选加固：约束请求方法与响应编码。 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Restrict
{
    /** 合法 HTTP 方法 */
    public const METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

    /** 合法响应编码 */
    public const ENCODES = ['json', 'jsonp', 'xml', 'view'];

    /**
     * @param string[]      $methods 允许的 HTTP 方法；[] = 不校验（由路由决定）
     * @param string[]|null $encode  允许的响应编码；null = 用全局默认
     */
    public function __construct(
        public readonly array  $methods = [],
        public readonly ?array $encode  = null,
    ) {}
}
