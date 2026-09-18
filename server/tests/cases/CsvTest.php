<?php

declare(strict_types=1);

use plugin\cccms\support\Csv;

return static function (): void {
    suite('Csv 导出');

    test('导出带 UTF-8 BOM（Excel 中文不乱码）', function (): void {
        $text = Csv::encode(['姓名', '年龄'], [['张三', 18]]);
        ok(str_starts_with($text, "\xEF\xBB\xBF"), '应以 BOM 开头');
        contains('姓名', $text);
        contains('张三', $text);
        contains('18', $text);
    });

    test('表头与数据行按顺序输出', function (): void {
        $text = Csv::encode(['a', 'b'], [['1', '2'], ['3', '4']]);
        $body = substr($text, 3);           // 去掉 BOM
        $body = str_replace(["\r\n", "\n"], '|', $body);
        same('a,b|1,2|3,4|', $body);
    });

    test('公式注入防护：危险前缀加单引号', function (): void {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $payload) {
            $text = Csv::encode(['v'], [[$payload]]);
            contains("'{$payload}", $text, "应对 {$payload} 加前缀");
        }
    });

    test('普通文本不受影响', function (): void {
        $text = Csv::encode(['v'], [['hello']]);
        ok(!str_contains($text, "'hello"), '普通文本不该被加引号');
    });

    test('逗号与引号被正确转义（fputcsv 包裹 + 双写引号）', function (): void {
        $text = Csv::encode(['v'], [['a,b"c']]);
        contains('"a,b""c"', $text, '应输出 RFC 4180 的转义形式');
    });
};
