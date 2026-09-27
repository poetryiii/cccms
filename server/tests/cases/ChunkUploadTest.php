<?php

declare(strict_types=1);

use plugin\cccms\support\ApiException;
use plugin\cccms\support\ChunkUpload;
use plugin\cccms\support\FileStorage;
use think\facade\Db;
use Webman\Http\UploadFile;

/**
 * P2-9：附件分片上传（init → chunk → complete）+ 秒传 + 完整性校验。
 *
 * 依赖真实 MySQL（`FileLogic::upload` 落 `sys_file`）与 Redis（上传会话）；
 * 本地存储驱动（`upload.storage_driver = local`）。临时文件刻意建在 `runtime/` 下：
 * Windows 上 `rename()` 跨卷会失败，`sys_get_temp_dir()` 可能落在 C 盘。
 */
return static function (): void {
    suite('附件分片上传（P2-9）');

    $tmpRoot = base_path() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'chunk-test';
    @mkdir($tmpRoot, 0700, true);

    $uploaded = [];   // 落库后返回的 path，用例结束时统一清物理文件

    $cleanup = static function () use (&$uploaded, $tmpRoot): void {
        foreach ($uploaded as $path) {
            try {
                FileStorage::delete($path);
            } catch (Throwable) {
            }
        }
        $uploaded = [];
        Db::name('file')->where('original_name', 'like', '__itest__%')->delete();
        // 清理测试临时文件
        foreach (glob($tmpRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $p) {
            @unlink($p);
        }
        @rmdir($tmpRoot);
    };

    /** 造一个测试分片文件（内容可控），返回路径 */
    $chunkFile = static function (string $content) use ($tmpRoot): string {
        // cleanup 会 rmdir 掉本目录，重新创建（file_put_contents 不会建目录）
        @mkdir($tmpRoot, 0700, true);
        $path = $tmpRoot . DIRECTORY_SEPARATOR . 'chunk-' . bin2hex(random_bytes(3));
        file_put_contents($path, $content);

        return $path;
    };

    /** 断言抛出的 ApiException 的 message 含关键字（完整性校验统一走 422） */
    $expectReject = static function (callable $fn, string $keyword) {
        try {
            $fn();
            fail('预期抛出异常（含「' . $keyword . '」），但没有抛出');
        } catch (ApiException $e) {
            contains($keyword, $e->getMessage(), '异常信息不符');
        }
    };

    test('init：危险扩展名在分片阶段就被拒绝（不会等传完 100MB）', function () use ($expectReject): void {
        $expectReject(
            static fn () => ChunkUpload::init(['name' => 'evil.svg', 'size' => 10, 'chunks' => 1], 0),
            'svg'
        );
    });

    test('init：总大小超上限被拒绝', function () use ($expectReject): void {
        $expectReject(
            static fn () => ChunkUpload::init(['name' => 'big.zip', 'size' => PHP_INT_MAX, 'chunks' => 1], 0),
            '大小'
        );
    });

    test('秒传：内容 hash + size 命中可见记录时直接返回，不落分片', function () use ($cleanup): void {
        try {
            $hash = sha1('instant-content');
            Db::name('file')->insertGetId([
                'name'          => '__itest__instant.txt',
                'original_name' => '__itest__instant.txt',
                'path'          => 'test/__itest__instant.txt',
                'url'           => '',
                'category_id'   => 0,
                'ext'           => 'txt',
                'mime'          => 'text/plain',
                'size'          => 15,
                'driver'        => 'local',
                'hash'          => $hash,
                'create_by'     => 0,
                'create_time'   => date('Y-m-d H:i:s'),
            ]);

            $result = ChunkUpload::init(
                ['name' => '__itest__instant.txt', 'size' => 15, 'chunks' => 1, 'hash' => $hash],
                0
            );

            same(true, $result['instant'], '命中应返回 instant=true');
            same('__itest__instant.txt', (string)($result['file']['name'] ?? ''), '秒传应复用已有记录');
        } finally {
            $cleanup();
        }
    }, true);

    test('完整流程：init → chunk → complete，合并后正确入库', function () use ($cleanup, $chunkFile, &$uploaded): void {
        try {
            $content = 'hello chunked upload ' . str_repeat('x', 64);
            $size    = strlen($content);

            $init = ChunkUpload::init(['name' => '__itest__a.txt', 'size' => $size, 'chunks' => 1], 0);
            same(false, $init['instant'], '未命中时应 instant=false');
            ok(isset($init['upload_id']) && $init['upload_id'] !== '', '应返回 upload_id');

            $chunk = $chunkFile($content);
            $recv  = ChunkUpload::receive(
                (string)$init['upload_id'],
                0,
                new UploadFile($chunk, 'chunk', '', UPLOAD_ERR_OK),
                0
            );
            same(1, $recv['received'], '第 0 片到达后 received 应为 1');

            $done = ChunkUpload::complete((string)$init['upload_id'], 0);
            same(false, $done['instant'], 'complete 结果 instant=false');
            same($size, (int)$done['file']['size'], '合并入库后大小应与声明一致');
            same('txt', (string)$done['file']['ext'], '扩展名取自原始文件名');

            $uploaded[] = (string)$done['file']['path'];

            // 会话与分片目录在 complete 后已清理
            ok(!is_dir($tmpRoot = base_path() . '/runtime/chunks/' . $init['upload_id']), '分片目录应已清理');
        } finally {
            $cleanup();
        }
    }, true);

    test('完整性：合并后大小与声明不符 → 拒绝', function () use ($chunkFile, $expectReject): void {
        $init = ChunkUpload::init(['name' => '__itest__b.txt', 'size' => 9999, 'chunks' => 1], 0);
        $chunk = $chunkFile('short');

        ChunkUpload::receive((string)$init['upload_id'], 0, new UploadFile($chunk, 'chunk', '', UPLOAD_ERR_OK), 0);

        $expectReject(
            static fn () => ChunkUpload::complete((string)$init['upload_id'], 0),
            '大小'
        );
    });

    test('完整性：内容哈希与声明不符 → 拒绝（防伪造秒传）', function () use ($chunkFile, $expectReject): void {
        $content = 'real content';
        $init    = ChunkUpload::init([
            'name' => '__itest__c.txt',
            'size' => strlen($content),
            'chunks' => 1,
            'hash' => sha1('something-else'),   // 客户端声明了一个错误哈希
        ], 0);
        $chunk = $chunkFile($content);

        ChunkUpload::receive((string)$init['upload_id'], 0, new UploadFile($chunk, 'chunk', '', UPLOAD_ERR_OK), 0);

        $expectReject(
            static fn () => ChunkUpload::complete((string)$init['upload_id'], 0),
            '哈希'
        );
    });

    test('会话归属：非本人 uploadId 表现为不存在（不泄露存在性）', function () use ($expectReject): void {
        $init = ChunkUpload::init(['name' => '__itest__d.txt', 'size' => 10, 'chunks' => 1], 100);
        $expectReject(
            static fn () => ChunkUpload::receive((string)$init['upload_id'], 0, null, 999),
            '会话'
        );
    });

    test('cleanup：清理超时未完成的分片目录', function () use ($cleanup): void {
        try {
            $root = base_path() . '/runtime/chunks';
            @mkdir($root, 0700, true);
            $stale = $root . '/deadbeef' . str_repeat('0', 24);
            @mkdir($stale, 0700, true);
            file_put_contents($stale . '/0', 'x');
            touch($stale, time() - 7200);   // 2 小时未动

            $result = ChunkUpload::cleanup(3600);
            ok($result['removed'] >= 1, '应清掉超时目录');
            ok(!is_dir($stale), '超时目录应被删除');
        } finally {
            $cleanup();
        }
    });

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
