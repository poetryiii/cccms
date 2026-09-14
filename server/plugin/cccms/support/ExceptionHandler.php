<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use Throwable;
use Webman\Exception\ExceptionHandlerInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/** 统一异常处理：ApiException → Result 结构；其它异常 → 500（debug 下透出详情）。 */
final class ExceptionHandler implements ExceptionHandlerInterface
{
    public function report(Throwable $exception)
    {
        Log::error((string)$exception);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ApiException) {
            return Result::fail($exception->getMessage(), $exception->getCode(), $exception->getData());
        }

        $message = config('app.debug', false) ? $exception->getMessage() : '服务器内部错误';
        return Result::fail($message, 500);
    }
}
