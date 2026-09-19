<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use RuntimeException;
use support\Log;
use Throwable;

/**
 * 极简 SMTP 邮件发送（零 composer 依赖）。
 *
 * 为什么自己写：项目一贯不引第三方包（Csv / Cipher / CronMatcher / SqlFileRunner 都是自实现），
 * 而发送验证码只需要「连上 SMTP → EHLO → AUTH → DATA」这一条最窄的路径，
 * PHPMailer / Symfony Mailer 这类全功能库对当前需求过重。
 *
 * 配置全部来自 `sys_config`（后台可改，保存即生效）：
 *   mail.enabled / host / port / username / password（type=password，走 SysConfig::getSecret 解密）
 *   mail.encryption（ssl=直接加密连接 / tls=STARTTLS / none）
 *   mail.from_address / from_name
 *
 * 任何失败都返回 false 并记一条 warning，**绝不抛异常打断找回流程** ——
 * 通道是否可用由调用方 `PasswordReset` 统一按「不泄露账号存在性」的方式处理。
 */
final class Mailer
{
    /** 连接与读写超时（秒）：常驻进程里不能让一次 SMTP 卡死一个 worker */
    private const TIMEOUT = 10;

    /** 配置是否完整到可以发信（不代表 SMTP 一定连通） */
    public static function enabled(): bool
    {
        if (!SysConfig::getBool('mail.enabled', false)) {
            return false;
        }
        if (SysConfig::getString('mail.host') === '') {
            return false;
        }

        return self::fromAddress() !== '';
    }

    /** 发送 HTML 邮件；成功 true，配置缺失 / 网络或协议失败 false */
    public static function send(string $to, string $subject, string $html): bool
    {
        $to = self::cleanHeader($to);
        if (!self::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $host       = SysConfig::getString('mail.host');
        $port       = max(1, SysConfig::getInt('mail.port', 465));
        $encryption = strtolower(SysConfig::getString('mail.encryption', 'ssl'));
        $username   = SysConfig::getString('mail.username');
        $password   = SysConfig::getSecret('mail.password');
        $from       = self::fromAddress();
        $fromName   = SysConfig::getString('mail.from_name', SysConfig::getString('system.name', 'CCCMS'));

        $socket = null;
        try {
            $socket = self::connect($host, $port, $encryption);
            self::expect($socket, [220]);
            self::command($socket, 'EHLO cccms.local', [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS 握手失败');
                }
                self::command($socket, 'EHLO cccms.local', [250]);
            }

            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode($password), [235]);
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            // 正文以裸字节写入（已是 base64 折行文本），不能走 command 的「写一行再读响应」
            self::write($socket, self::message($from, $fromName, $to, $subject, $html));
            self::command($socket, '.', [250]);
            self::command($socket, 'QUIT', [221]);

            return true;
        } catch (Throwable $e) {
            Log::warning('mail send failed: ' . $e->getMessage());

            return false;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private static function fromAddress(): string
    {
        $from = SysConfig::getString('mail.from_address', SysConfig::getString('mail.username'));

        return self::cleanHeader($from);
    }

    /** 去掉换行，防止邮件头注入 */
    private static function cleanHeader(string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $value) ?? $value);
    }

    /** @return resource */
    private static function connect(string $host, int $port, string $encryption)
    {
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket    = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, self::TIMEOUT);
        if (!is_resource($socket)) {
            throw new RuntimeException("SMTP 连接失败：{$errstr} ({$errno})");
        }
        stream_set_timeout($socket, self::TIMEOUT);

        return $socket;
    }

    /** 发送一条命令并校验响应码 */
    private static function command($socket, string $command, array $codes): void
    {
        self::write($socket, $command . "\r\n");
        self::expect($socket, $codes);
    }

    private static function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) === false) {
            throw new RuntimeException('SMTP 写入失败');
        }
    }

    /**
     * 读取完整响应（多行会读到最后一行 `NNN ` 为止）。
     *
     * @param int[] $codes 允许的响应码
     */
    private static function expect($socket, array $codes): void
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            // 第 4 个字符不是 '-' 即为末行（RFC 5321 多行响应约定）
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($response === '') {
            throw new RuntimeException('SMTP 无响应（连接可能已断开）');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP 响应异常：' . trim($response));
        }
    }

    /** 组装 MIME 报文（中文主题 / 发件人名按 RFC 2047 base64 编码，正文 base64 折行） */
    private static function message(string $from, string $fromName, string $to, string $subject, string $html): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode(self::cleanHeader($subject)) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";
    }
}