<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 跨域处理。 */
class Cors implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if ($request->method() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $handler($request);
        }

        return $response->withHeaders([
            'Access-Control-Allow-Origin'      => '*',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'false',
            'Access-Control-Max-Age'           => '86400',
        ]);
    }
}
