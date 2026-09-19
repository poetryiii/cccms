<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 本地存储（`public/storage`）静态文件的响应头加固。
 *
 * 本地驱动与后台**同域**直出，浏览器会按响应头的 Content-Type 内联渲染：
 * 一个含 `<script>` 的 SVG 附件，管理员点开预览即执行脚本，而 token 存在
 * `localStorage`，可被直接读取并接管后台（存储型 XSS）。
 *
 * 这里做两件事（只作用于 `/storage/` 前缀，不影响前端产物等其它静态资源）：
 * 1. 一律下发 `X-Content-Type-Options: nosniff`，禁止浏览器按内容嗅探类型；
 * 2. 除图片与 PDF 外一律 `Content-Disposition: attachment`，强制下载而非内联渲染。
 *
 * 上传侧的类型收紧见 `support/storage/StorageDriver`（`DANGEROUS_EXT` + 魔数校验），
 * 本中间件负责**兜住存量文件**：白名单收紧前已落盘的 SVG 也只会被下载。
 */
final class StorageGuard
{
    /** 允许内联渲染的类型：图片（缩略图）与 PDF（内置阅读器） */
    private const INLINE_SAFE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'pdf'];

    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);

        $path = ltrim($request->path(), '/');
        if (!str_starts_with($path, 'storage/')) {
            return $response;
        }

        $response->withHeaders(['X-Content-Type-Options' => 'nosniff']);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::INLINE_SAFE, true)) {
            // 对象名由服务端随机生成（形如 20260912/103000ab12cd34ef.png），basename 不含路径分隔符
            $response->withHeaders([
                'Content-Disposition' => 'attachment; filename="' . rawurlencode(basename($path)) . '"',
            ]);
        }

        return $response;
    }
}
