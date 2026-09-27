<?php

declare(strict_types=1);

namespace plugin\cccms\command\task;

use plugin\cccms\support\ChunkUpload;
use plugin\cccms\support\CrontabTask;

/**
 * 清理超时未完成的分片上传（框架内置定时任务）。
 *
 * 分片上传的临时文件落在 `runtime/chunks/{uploadId}/`，正常流程在 `complete` 里
 * 自行清理；但「init 之后再也不 complete」的会话会留下孤儿目录，必须定期回收。
 *
 * 清理依据目录 mtime（`ChunkUpload::cleanup()`）：写入分片会刷新它，因此
 * 「超过 24 小时没有新分片写入」即视为已放弃，直接删除并作废对应会话。
 * `init` 里也有每小时一次的顺带清理兜底，本任务只是保证「长时间无人上传」时也能收敛。
 */
class ChunkCleanTask implements CrontabTask
{
    public function run(array $params = []): string
    {
        $ttl = (int)($params['ttl'] ?? ChunkUpload::SESSION_TTL);

        $result = ChunkUpload::cleanup($ttl);

        return sprintf(
            '已清理 %d 个超时未完成的分片会话，释放约 %.1f MB',
            $result['removed'],
            $result['bytes'] / 1048576
        );
    }
}
