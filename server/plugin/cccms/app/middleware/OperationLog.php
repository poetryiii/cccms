<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\PermissionMeta;
use plugin\cccms\support\SysConfig;
use support\Log;
use think\facade\Db;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 操作日志：写操作落库 sys_log。
 *
 * 落库内容分三类，便于事后回放：
 *   1. 语义化定位：`node`（权限节点 slug）+ `title`（注解里的操作名）。
 *      只看 `/dict/save` 无法判断业务含义，`cccms:dict:save` + 「新增字典类型」才可读。
 *   2. 请求与响应：`params`（query + body，递归脱敏，含上传文件名）+ `result`（响应体）。
 *   3. 环境信息：`ip` / `ua` / `method` / `path` / `cost` / `status_code`。
 */
class OperationLog implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /** 需要脱敏的字段（小写比对，递归生效） */
    private const SENSITIVE = [
        'password', 'old_password', 'new_password', 'password_confirm',
        'token', 'access_token', 'refresh_token', 'secret',
    ];

    /** text 上限 65535 字节，utf8mb4 单字符最多 4 字节，8000 字符足够安全 */
    private const TEXT_LIMIT = 8000;

    public function process(Request $request, callable $handler): Response
    {
        $start = microtime(true);

        try {
            $response = $handler($request);
            $cost = $this->cost($start);
            $this->alertSlow($request, $cost);
            $this->write($request, $response, null, $cost);

            return $response;
        } catch (Throwable $e) {
            $cost = $this->cost($start);
            $this->alertSlow($request, $cost);
            $this->write($request, null, $e, $cost);
            throw $e;
        }
    }

    private function cost(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }

    /**
     * 慢接口告警：耗时超过阈值时写一条独立 channel（slow）的告警日志。
     *
     * 阈值来自 `log.slow_threshold`（毫秒，0 = 关闭）。覆盖**所有**请求（含 GET），
     * 不依赖「是否写操作日志」—— 慢查询同样值得盯；告警失败绝不影响业务。
     */
    private function alertSlow(Request $request, int $cost): void
    {
        $threshold = SysConfig::getInt('log.slow_threshold', 0);
        if ($threshold <= 0 || $cost < $threshold) {
            return;
        }

        try {
            Log::channel('slow')->warning(sprintf(
                '%s %s cost=%dms user=%s ip=%s trace=%s',
                $request->method(),
                '/' . ltrim($request->path(), '/'),
                $cost,
                (string)($request->user?->username ?? '-'),
                (string)($request->getRealIp() ?: '-'),
                (string)($request->traceId ?? '-')
            ));
        } catch (Throwable) {
            // 告警失败不影响业务
        }
    }

    private function write(Request $request, ?Response $response, ?Throwable $error, int $cost): void
    {
        // 登录接口由 AuthLogic 自己记（type='login'），这里跳过：那时没有用户上下文，
        // 中间件记出来的 user_id=0 且 node/title 为空，是重复且不友好的记录
        if ($request->method() === 'POST' && '/' . ltrim($request->path(), '/') === '/auth/login') {
            return;
        }

        // 写操作必记；读操作默认不记，可由 log.record_read 打开（日志量会明显增加）
        if (!in_array($request->method(), self::WRITE_METHODS, true)
            && !SysConfig::getBool('log.record_read', false)) {
            return;
        }

        try {
            $user = $request->user;

            // 路径语义化：控制器 + 方法 → 权限节点 slug 与注解标题
            $meta = PermissionMeta::of((string)$request->controller, (string)$request->action);

            // query + body 合并，保留完整入参（同名时以 body 为准）
            $params = array_merge($request->get(), $request->post());
            $params = self::redact($params);

            // 上传接口的 body 是空的，补上原始文件名，否则日志看不出传了什么
            $files = self::fileNames($request);
            if ($files) {
                $params['_files'] = $files;
            }

            if ($error !== null) {
                $statusCode = $error instanceof ApiException ? $error->getCode() : 500;
                $result     = $error->getMessage();
            } else {
                $statusCode = $response?->getStatusCode() ?? 200;
                $result     = (string)$response?->rawBody();
            }

            Db::name('log')->insert([
                'user_id'     => $user?->id ?? 0,
                'username'    => $user?->username ?? '',
                'type'        => 'operation',
                'status'      => $error !== null ? 0 : 1,
                'message'     => $error !== null ? self::clip($error->getMessage(), 255) : '',
                'method'      => $request->method(),
                'path'        => '/' . ltrim($request->path(), '/'),
                'node'        => (string)($meta['slug'] ?? ''),
                'title'       => (string)($meta['title'] ?? ''),
                // 由 Cors（中间件链第一环）生成，可把一次请求的多条记录串起来
                'trace_id'    => (string)($request->traceId ?? ''),
                'ip'          => (string)($request->getRealIp() ?: ''),
                'ua'          => self::clip((string)$request->header('User-Agent', ''), 255),
                'params'      => self::encode($params),
                'result'      => self::clip($result, self::TEXT_LIMIT),
                'status_code' => $statusCode,
                'cost'        => $cost,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // 日志写入失败绝不影响业务
            Log::error('operation log failed: ' . $e->getMessage());
        }
    }

    /**
     * 递归脱敏：密码 / token 这类字段可能藏在嵌套结构里。
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 3) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string)$key), self::SENSITIVE, true)) {
                $data[$key] = '******';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }

    /**
     * 收集上传文件名（形如 `file:报告.pdf`）。
     *
     * @return string[]
     */
    private static function fileNames(Request $request): array
    {
        $out = [];

        try {
            foreach ((array)$request->file() as $field => $item) {
                foreach (is_array($item) ? $item : [$item] as $file) {
                    if (is_object($file) && method_exists($file, 'getUploadName')) {
                        $out[] = $field . ':' . $file->getUploadName();
                    }
                }
            }
        } catch (Throwable) {
            // 取文件名失败不影响日志主体
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        return self::clip(
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::TEXT_LIMIT
        );
    }

    private static function clip(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }
}
