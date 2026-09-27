<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\logic\FileLogic;
use plugin\cccms\support\storage\StorageDriver;
use support\Log;
use support\Redis;
use Throwable;
use Webman\Http\UploadFile;

/**
 * 附件分片上传（P2-9）。
 *
 * ## 为什么要它
 *
 * 单请求上传受 `upload.max_size`（默认 10MB）限制，且**弱网失败要整包重传**。
 * 分片上传把文件切成固定大小的片逐片传，断了只补缺的那几片（`init` 会返回
 * `received` 列表，客户端据此续传），大文件也不会把单次请求撑爆。
 *
 * ## 三阶段
 *
 * | 阶段 | 做什么 |
 * |------|--------|
 * | `init` | 校验扩展名 / 总大小 → **尝试秒传**（内容已存在直接返回记录）→ 建会话目录 |
 * | `chunk` | 落盘一片到 `runtime/chunks/{uploadId}/{index}`，幂等（重传覆盖） |
 * | `complete` | 校验片齐 → 合并 → **校验声明大小与哈希** → 交回现有上传链路入库 |
 *
 * ## 几个刻意的设计
 *
 * 1. **合并后仍走 `FileLogic::upload()`**：扩展名 / 魔数 / 去重 / 数据权限 / 字段规则
 *    全部复用同一条链路，不存在「分片上传绕过了校验」的第二套逻辑。
 *    区别只有大小上限 —— 通过 `FileLogic::upload(..., $maxBytes)` 显式传入
 *    `upload.chunk_max_size`（驱动实例被静态缓存，不能用可变状态表达"本次放宽"）。
 * 2. **四种驱动行为一致**：分片**只暂存在本地**，`complete` 时把合并好的文件当成一个
 *    普通上传交给驱动（本地驱动是 `rename`，云驱动是整体上传）。云驱动的原生分片接口
 *    能省一次中转，但四种驱动要各写一套且必须真账号联调，本轮不做（见开发计划的取舍）。
 * 3. **完整性校验**：合并后总字节数必须等于 `init` 声明的大小，声明了哈希的还要
 *    `sha1_file` 对得上 —— 否则「客户端漏传一片」会静默生成一个损坏的附件。
 * 4. **秒传只认「自己看得见的记录」**：与 `FileLogic::upload()` 的去重同一口径
 *    （走带作用域的查询）。否则等于把别人上传的附件路径告诉当前用户。
 * 5. **会话放 Redis、分片放磁盘**：会话有 TTL（默认 24h）自动过期；磁盘目录由
 *    `cleanup()` 按 mtime 兜底清理（`init` 里每小时顺带跑一次，另有内置任务可挂定时）。
 */
final class ChunkUpload
{
    /** 会话 key 前缀 */
    private const SESSION_PREFIX = 'cccms:upload:';

    /** 会话有效期（秒）：24 小时未完成即失效，客户端需要重新 init（清理任务引用它，故 public） */
    public const SESSION_TTL = 86400;

    /** 清理节流 key：同一小时内只顺带清理一次 */
    private const GC_KEY = 'cccms:upload:gc';

    /** 建议分片大小（字节）：4MB —— 单次请求体小、片数也不至于爆炸 */
    public const CHUNK_SIZE = 4194304;

    /** 单片允许的上限（字节）：8MB，给不同粒度的客户端留 2 倍余量 */
    private const CHUNK_MAX_BYTES = 8388608;

    /** 片数上限：防止「1 字节一片」把目录刷爆 */
    private const MAX_CHUNKS = 20000;

    /** 总大小上限的兜底值（MB），实际取配置 `upload.chunk_max_size` */
    private const DEFAULT_TOTAL_MB = 2048;

    /**
     * 开始一次分片上传。
     *
     * @param  array<string,mixed> $input name / size / chunks / hash? / category_id?
     * @return array{instant:bool,file?:array<string,mixed>,upload_id?:string,chunk_size?:int,total_chunks?:int,received?:int[]}
     */
    public static function init(array $input, int $userId): array
    {
        self::gcThrottled();

        $name     = trim((string)($input['name'] ?? ''));
        $size     = (int)($input['size'] ?? 0);
        $chunks   = (int)($input['chunks'] ?? 0);
        $hash     = strtolower(trim((string)($input['hash'] ?? '')));
        $category = max(0, (int)($input['category_id'] ?? 0));

        if ($name === '') {
            throw new ApiException(I18n::t('file.file_name_required'), 422);
        }

        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        // 类型不合法要在只传了几百字节时就拒绝，而不是等用户传完 100MB
        StorageDriver::assertExtAllowed($ext);

        if ($size <= 0) {
            throw new ApiException(I18n::t('file.file_empty'), 422);
        }
        $maxBytes = self::maxBytes();
        if ($size > $maxBytes) {
            throw new ApiException(
                I18n::t('file.chunk_size_exceeded', ['max' => (int)round($maxBytes / 1048576)]),
                422
            );
        }
        if ($chunks <= 0 || $chunks > self::MAX_CHUNKS) {
            throw new ApiException(I18n::t('file.chunk_count_invalid', ['max' => self::MAX_CHUNKS]), 422);
        }

        // 秒传：内容哈希 + 大小都对得上，且这条记录在当前数据范围内可见
        if ($hash !== '') {
            $reusable = FileLogic::findReusable($hash, $size);
            if ($reusable !== null) {
                return ['instant' => true, 'file' => $reusable];
            }
        }

        $uploadId = bin2hex(random_bytes(16));
        $dir      = self::dir($uploadId);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ApiException(I18n::t('file.chunk_dir_failed'), 500);
        }

        self::save($uploadId, [
            'user_id'     => $userId,
            'name'        => $name,
            'size'        => $size,
            'chunks'      => $chunks,
            'hash'        => $hash,
            'category_id' => $category,
            'received'    => [],
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        return [
            'instant'      => false,
            'upload_id'    => $uploadId,
            'chunk_size'   => self::CHUNK_SIZE,
            'total_chunks' => $chunks,
            'received'     => [],
        ];
    }

    /**
     * 接收一片（幂等：同 index 重传直接覆盖，弱网重试不需要特殊处理）。
     *
     * @return array{received:int,total_chunks:int}
     */
    public static function receive(string $uploadId, int $index, ?UploadFile $file, int $userId): array
    {
        $session = self::load($uploadId, $userId);

        $chunks = (int)$session['chunks'];
        if ($index < 0 || $index >= $chunks) {
            throw new ApiException(I18n::t('file.chunk_index_invalid', ['max' => $chunks - 1]), 422);
        }
        if ($file === null) {
            throw new ApiException(I18n::t('file.file_required'), 422);
        }
        if (!$file->isValid()) {
            throw new ApiException(I18n::t('file.chunk_upload_failed'), 422);
        }
        // 单片大小上限：挡住「把 2GB 一次性塞进 chunk 接口」绕过总大小校验
        if ((int)$file->getSize() > self::CHUNK_MAX_BYTES) {
            throw new ApiException(
                I18n::t('file.chunk_too_large', ['max' => (int)(self::CHUNK_MAX_BYTES / 1048576)]),
                422
            );
        }

        $target = self::dir($uploadId) . DIRECTORY_SEPARATOR . $index;
        $file->move($target);

        $received = (array)$session['received'];
        if (!in_array($index, $received, true)) {
            $received[] = $index;
            sort($received);
        }
        $session['received'] = $received;
        self::save($uploadId, $session);

        return ['received' => count($received), 'total_chunks' => $chunks];
    }

    /**
     * 合并并入库。
     *
     * @return array{instant:bool,file:array<string,mixed>}
     */
    public static function complete(string $uploadId, int $userId): array
    {
        $session = self::load($uploadId, $userId);

        $chunks   = (int)$session['chunks'];
        $size     = (int)$session['size'];
        $received = array_map('intval', (array)$session['received']);

        $missing = array_values(array_diff(range(0, $chunks - 1), $received));
        if ($missing !== []) {
            throw new ApiException(
                I18n::t('file.chunk_missing', ['count' => count($missing), 'first' => $missing[0]]),
                422
            );
        }

        $merged = self::dir($uploadId) . '.part';
        self::merge($uploadId, $chunks, $merged);

        try {
            $actual = (int)(filesize($merged) ?: 0);
            if ($actual !== $size) {
                throw new ApiException(
                    I18n::t('file.chunk_size_mismatch', ['expected' => $size, 'actual' => $actual]),
                    422
                );
            }

            $hash = (string)($session['hash'] ?? '');
            if ($hash !== '' && sha1_file($merged) !== $hash) {
                // 客户端声明的内容哈希与真实内容不符：可能是传坏了一片，也可能在说谎
                throw new ApiException(I18n::t('file.chunk_hash_mismatch'), 422);
            }

            // 交回既有链路：扩展名 / 魔数 / 去重 / 数据权限 / 字段规则全部复用；
            // 只有大小上限换成分片上传的上限
            $result = FileLogic::upload(
                new UploadFile($merged, (string)$session['name'], '', UPLOAD_ERR_OK),
                $userId,
                (int)($session['category_id'] ?? 0),
                self::maxBytes()
            );
        } finally {
            self::purge($uploadId);
        }

        return ['instant' => false, 'file' => $result];
    }

    /**
     * 清理超时未完成的分片。
     *
     * 依据目录 mtime（写入分片会更新它）：比「会话是否还在 Redis 里」更简单，
     * 也不依赖 Redis 可用 —— 代价是「刚传过一片但超过 TTL 没继续」的会话也会被清掉，
     * 而那本来就是要清的对象。
     *
     * @return array{removed:int,bytes:int}
     */
    public static function cleanup(int $ttlSeconds = self::SESSION_TTL): array
    {
        $root = self::root();
        $removed = 0;
        $bytes   = 0;

        foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            $isDir = is_dir($path);
            $mtime = @filemtime($path) ?: 0;
            if ($mtime > 0 && time() - $mtime < $ttlSeconds) {
                continue;
            }

            $bytes += $isDir ? self::dirSize($path) : (int)(@filesize($path) ?: 0);
            $isDir ? self::removeDir($path) : @unlink($path);
            $removed++;

            // 目录名就是 uploadId：顺手把还活着的会话一并作废，避免客户端拿着
            // 已清空的会话继续传片（那就变成"少片"错误，不如直接说会话过期）
            if ($isDir) {
                self::forget(basename($path));
            }
        }

        return ['removed' => $removed, 'bytes' => $bytes];
    }

    /** 分片总大小上限（字节） */
    public static function maxBytes(): int
    {
        $mb = SysConfig::getInt('upload.chunk_max_size', self::DEFAULT_TOTAL_MB);

        return ($mb > 0 ? $mb : self::DEFAULT_TOTAL_MB) * 1048576;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /** 分片根目录（运行时目录，随部署持久化但不进版本控制） */
    private static function root(): string
    {
        $root = base_path() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'chunks';
        if (!is_dir($root)) {
            @mkdir($root, 0700, true);
        }

        return $root;
    }

    private static function dir(string $uploadId): string
    {
        return self::root() . DIRECTORY_SEPARATOR . self::safeId($uploadId);
    }

    /** 只允许十六进制 id：目录名直接来自客户端，必须防穿越 */
    private static function safeId(string $uploadId): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $uploadId) !== 1) {
            throw new ApiException(I18n::t('file.upload_session_invalid'), 404);
        }

        return $uploadId;
    }

    private static function key(string $uploadId): string
    {
        return self::SESSION_PREFIX . $uploadId;
    }

    /**
     * 读取会话并校验归属。
     *
     * 归属不符时**返回 404 而不是 403**：uploadId 是随机 128 位，能猜中本身就说明
     * 是内部泄露；对外统一表现为「会话不存在」，不给探测者任何额外信息。
     *
     * @return array<string,mixed>
     */
    private static function load(string $uploadId, int $userId): array
    {
        $session = self::read($uploadId);
        if ($session === null || (int)($session['user_id'] ?? 0) !== $userId) {
            throw new ApiException(I18n::t('file.upload_session_expired'), 404);
        }

        return $session;
    }

    /** @return array<string,mixed>|null */
    private static function read(string $uploadId): ?array
    {
        try {
            $raw = Redis::get(self::key(self::safeId($uploadId)));
        } catch (Throwable $e) {
            throw new ApiException(I18n::t('file.chunk_redis_required'), 500);
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $session */
    private static function save(string $uploadId, array $session): void
    {
        try {
            Redis::setex(
                self::key($uploadId),
                self::SESSION_TTL,
                (string)json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
            throw new ApiException(I18n::t('file.chunk_redis_required'), 500);
        }
    }

    private static function forget(string $uploadId): void
    {
        try {
            if (preg_match('/^[0-9a-f]{32}$/', $uploadId) === 1) {
                Redis::del(self::key($uploadId));
            }
        } catch (Throwable) {
            // 清理是尽力而为，失败不影响正确性
        }
    }

    /**
     * 顺序合并分片。
     *
     * 用流式拷贝而不是 `file_get_contents` 拼接：100MB 量级直接读进内存会撞 `memory_limit`。
     */
    private static function merge(string $uploadId, int $chunks, string $target): void
    {
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new ApiException(I18n::t('file.chunk_merge_failed'), 500);
        }

        try {
            for ($i = 0; $i < $chunks; $i++) {
                $part = self::dir($uploadId) . DIRECTORY_SEPARATOR . $i;
                if (!is_file($part)) {
                    throw new ApiException(I18n::t('file.chunk_missing', ['count' => 1, 'first' => $i]), 422);
                }
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new ApiException(I18n::t('file.chunk_merge_failed'), 500);
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
    }

    /** 删除分片目录、合并临时文件与会话 */
    private static function purge(string $uploadId): void
    {
        self::removeDir(self::dir($uploadId));
        @unlink(self::dir($uploadId) . '.part');
        self::forget($uploadId);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function dirSize(string $dir): int
    {
        $size = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            $size += is_dir($path) ? self::dirSize($path) : (int)(@filesize($path) ?: 0);
        }

        return $size;
    }

    /** 顺带清理（每小时最多一次）：不依赖定时任务也能收敛脏分片 */
    private static function gcThrottled(): void
    {
        try {
            $ok = Redis::set(self::GC_KEY, '1', 'EX', 3600, 'NX');
            if (!$ok) {
                return;
            }
        } catch (Throwable) {
            // Redis 不可用时不做节流判断：直接清理一次也无妨（下面还有 mtime 判据）
        }

        try {
            $result = self::cleanup();
            if ($result['removed'] > 0) {
                Log::info(sprintf(
                    'chunk upload gc: removed %d items, %s',
                    $result['removed'],
                    HealthProbe::bytes((float)$result['bytes'])
                ));
            }
        } catch (Throwable $e) {
            // 清理失败不影响本次上传
            Log::error('chunk upload gc failed: ' . $e->getMessage());
        }
    }
}
