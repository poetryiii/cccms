<?php

declare(strict_types=1);

namespace plugin\cccms\basic;

use plugin\cccms\support\Result;
use Webman\Http\Response;

/** 业务控制器基类。 */
abstract class BaseController
{
    protected function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return Result::ok($data, $message);
    }

    protected function fail(string $message, int $code = 1, mixed $data = null): Response
    {
        return Result::fail($message, $code, $data);
    }
}
