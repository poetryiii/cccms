<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use RuntimeException;

/** 业务 / 鉴权异常，统一由 ExceptionHandler 渲染为 Result 结构。 */
class ApiException extends RuntimeException
{
    private array $data;

    public function __construct(string $message, int $code = 500, array $data = [])
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
