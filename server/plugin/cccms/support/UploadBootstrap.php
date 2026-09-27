<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use Webman\Bootstrap;
use Workerman\Protocols\Http;
use Workerman\Worker;

/**
 * 启动时确保上传临时目录有效。
 *
 * ## 为什么要这一层
 *
 * Workerman 解析 multipart 上传时用 `tempnam(HTTP::uploadTmpDir(), 'workerman.upload.')`
 * 给每个上传文件落临时文件；而 `HTTP::uploadTmpDir()` 默认取 `upload_tmp_dir` ini 配置，
 * 没有才回退 `sys_get_temp_dir()`。**如果 ini 里配了一个不存在 / 不可写的目录**，
 * `tempnam()` 会回退到系统临时目录并抛一条 notice —— 而 webman 的全局错误处理器
 * （`support/bootstrap.php`）把任何 notice 都转成 `ErrorException`，于是上传直接 500：
 *
 *   `tempnam(): file created in the system's temporary directory`
 *
 * 这类「环境配置漂移」不该靠运维记得建目录来兜底，所以在这里收敛：
 * ini 配置的目录**有效才采信**，否则一律落到本应用 `runtime/` 下自己创建、自己可写的目录。
 */
class UploadBootstrap implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        $configured = (string)ini_get('upload_tmp_dir');

        // 只有「存在且可写」的 ini 目录才值得采信；否则落 runtime，保证 100% 可写。
        if ($configured !== '' && is_dir($configured) && is_writable($configured)) {
            Http::uploadTmpDir($configured);
            return;
        }

        $dir = runtime_path() . DIRECTORY_SEPARATOR . 'upload-tmp';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            // 极端情况：runtime 都建不出来。退回系统临时目录，让上传至少还能跑
            //（此时 workerman 用 sys_get_temp_dir()，不会再触发上面那条 notice）。
            Http::uploadTmpDir((string)sys_get_temp_dir());
            return;
        }

        Http::uploadTmpDir($dir);
    }
}
