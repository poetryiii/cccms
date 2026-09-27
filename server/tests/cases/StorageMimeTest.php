<?php

declare(strict_types=1);

use plugin\cccms\support\storage\StorageDriver;
use Webman\Http\UploadFile;

return static function (): void {
    suite('上传 MIME（R-07 存服务端探测值）');

    test('入库 MIME 取服务端探测值，客户端上报值被忽略', function (): void {
        if (!class_exists(\finfo::class)) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " MIME 探测（缺少 finfo 扩展）\n";
            return;
        }

        // 1x1 PNG（真实内容），但客户端谎报为 text/html
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $tmp = tempnam(sys_get_temp_dir(), 'cccms-mime-');
        file_put_contents($tmp, $png);

        $file = new UploadFile($tmp, 'evil.html', 'text/html', UPLOAD_ERR_OK);

        $mime = StorageDriver::probeMime($file);
        unlink($tmp);

        same('image/png', $mime, '应以 finfo 探测到的 image/png 入库，而不是客户端上报的 text/html');
    });

    test('finfo 不可用 / 探测为 octet-stream 时回退客户端上报值（不让整列为空）', function (): void {
        $tmp = tempnam(sys_get_temp_dir(), 'cccms-mime-');
        file_put_contents($tmp, 'plain text without magic bytes');

        $file = new UploadFile($tmp, 'a.unknownext', 'application/x-custom', UPLOAD_ERR_OK);
        $mime = StorageDriver::probeMime($file);
        unlink($tmp);

        ok($mime !== '', 'MIME 不应为空');
        // finfo 可用时把无魔数文本识别为 text/plain，不可用时回退客户端值，两者都不该是 octet-stream
        ok(
            $mime === 'text/plain' || $mime === 'application/x-custom',
            "无可识别魔数时应给出可用值，实际 {$mime}"
        );
    });
};
