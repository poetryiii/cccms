<?php

declare(strict_types=1);

/**
 * 跨域白名单。
 *
 * 默认**为空 = 只允许同源**。本项目的后台前端始终与接口同源：开发环境由 Vite
 * 把 `/api` 代理到 8787（见 `frontend/vite.config.ts`），生产环境由 Nginx 反代到
 * 同一域名。同源请求浏览器不会发起 CORS 校验，因此默认配置不影响现有部署。
 *
 * 前后端**分域部署**时，在 .env 里配置逗号分隔的白名单：
 *   CORS_ORIGIN=https://admin.example.com,https://ops.example.com
 *
 * 为什么不再用 `*`：`Allow-Origin: *` 意味着**任何站点**都能代表用户发起请求，
 * 且无法按环境收紧（比如只在测试环境放开）。白名单只放行明确信任的来源。
 */
return [
    'origin' => (string)(getenv('CORS_ORIGIN') ?: ''),
];
