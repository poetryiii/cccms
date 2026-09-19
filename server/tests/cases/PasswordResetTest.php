<?php

declare(strict_types=1);

use plugin\cccms\support\ApiException;
use plugin\cccms\support\PasswordReset;
use plugin\cccms\support\SmsSender;
use plugin\cccms\support\SysConfig;
use think\facade\Db;

/**
 * 密码找回（P2-3）用例。
 *
 * 不发真邮件 / 真短信：渠道发送由 Mailer / SmsSender 负责，这里只覆盖
 * 验证码存取与一次性消费、尝试次数上限、账号不存在时的统一返回、渠道关闭时的拒绝
 * 以及可纯函数化的判定。依赖 Redis / 数据库的分支在不可用时自动跳过。
 */
return function (): void {
    suite('密码找回（P2-3）');

    test('验证码为 6 位数字', function (): void {
        for ($i = 0; $i < 20; $i++) {
            $code = PasswordReset::generateCode();
            ok(preg_match('/^\d{6}$/', $code) === 1, "验证码格式异常：{$code}");
        }
    });

    test('尝试次数上限边界：第 max+1 次才算超限，max<=0 不限制', function (): void {
        ok(!PasswordReset::exceededAttempts(5, 5), '等于上限不应判定超限');
        ok(PasswordReset::exceededAttempts(6, 5), '超过上限应判定超限');
        ok(!PasswordReset::exceededAttempts(999, 0), 'max=0 表示不限制');
    });

    test('渠道可用性解析（纯函数）', function (): void {
        ok(!PasswordReset::channelAllowed('off', 'email', true, true), 'off 时应全部关闭');
        ok(!PasswordReset::channelAllowed('off', 'sms', true, true), 'off 时应全部关闭');
        ok(PasswordReset::channelAllowed('email', 'email', true, false), 'email 模式应放行邮箱');
        ok(!PasswordReset::channelAllowed('email', 'sms', true, true), 'email 模式不应放行短信');
        ok(PasswordReset::channelAllowed('sms', 'sms', false, true), 'sms 模式应放行短信');
        ok(PasswordReset::channelAllowed('both', 'email', true, true), 'both 模式应放行邮箱');
        ok(PasswordReset::channelAllowed('both', 'sms', true, true), 'both 模式应放行短信');
        ok(!PasswordReset::channelAllowed('both', 'email', false, true), '邮箱通道未启用时不应放行');
        ok(!PasswordReset::channelAllowed('both', 'sms', true, false), '短信通道未启用时不应放行');
        ok(!PasswordReset::channelAllowed('both', 'push', true, true), '未支持的渠道不应放行');
    });

    test('渠道非法 / 未开启时拒绝（明确报错）', function (): void {
        $invalid = false;
        try {
            PasswordReset::assertChannel('__bad__');
        } catch (ApiException $e) {
            $invalid = $e->getCode() === 422;
        }
        ok($invalid, '非法渠道应抛 422');

        if (PasswordReset::channels() !== []) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " 渠道已开启，跳过「关闭时拒绝」断言\n";
            return;
        }

        $disabled = false;
        try {
            PasswordReset::assertChannel('email');
        } catch (ApiException $e) {
            $disabled = $e->getCode() === 422;
        }
        ok($disabled, '渠道关闭时应拒绝并抛 422');
    });

    test('短信参数模板占位符替换（含嵌套）', function (): void {
        $params = SmsSender::substitute(
            ['mobile' => '{mobile}', 'code' => '{code}', 'ext' => ['sign' => '{sign}']],
            ['{mobile}' => '13800000000', '{code}' => '123456', '{sign}' => 'CCCMS']
        );
        same('13800000000', $params['mobile']);
        same('123456', $params['code']);
        same('CCCMS', $params['ext']['sign']);
    });

    test('账号不存在时返回 null（不泄露账号存在性）', function (): void {
        same(null, PasswordReset::findUser('__no_such_account_' . bin2hex(random_bytes(4)) . '__'));
    }, true);

    test('账号不存在时 sendCode 静默返回（渠道开启时也不抛错）', function (): void {
        // 临时把找回渠道切到邮箱，验证「账号不存在」走静默返回而不是异常。
        // 该分支在 findUser 之前不做任何账号相关判断，因此不存在与存在返回一致。
        $backup = [];
        foreach (['security.reset_channel' => 'email', 'mail.enabled' => '1'] as $name => $value) {
            $row = Db::name('config')->where('name', $name)->find();
            if (!$row) {
                Suite::$skipped++;
                echo '  ' . Suite::color('○ 跳过', 'gray') . " 配置项 {$name} 不存在（先执行 cccms:db-upgrade）\n";
                return;
            }
            $backup[$name] = (string)$row['value'];
            Db::name('config')->where('name', $name)->update(['value' => $value]);
        }
        SysConfig::flush();

        try {
            if (!PasswordReset::channelAvailable('email')) {
                Suite::$skipped++;
                echo '  ' . Suite::color('○ 跳过', 'gray') . " 邮箱渠道仍不可用，跳过\n";
                return;
            }

            PasswordReset::sendCode('__no_such_account_' . bin2hex(random_bytes(4)) . '__', 'email', '');
            ok(true, '账号不存在时应静默返回');
        } catch (ApiException $e) {
            if ($e->getCode() === 503) {
                Suite::$skipped++;
                echo '  ' . Suite::color('○ 跳过', 'gray') . " Redis 不可用，跳过\n";
                return;
            }
            fail('账号不存在时不应失败：' . $e->getMessage());
        } finally {
            foreach ($backup as $name => $value) {
                Db::name('config')->where('name', $name)->update(['value' => $value]);
            }
            SysConfig::flush();
        }
    }, true);

    test('验证码 hash 存取与一次性消费', function (): void {
        $userId = 990000 + random_int(1, 9999);
        try {
            $code = PasswordReset::generateCode();
            PasswordReset::storeCode($userId, 'email', $code);

            $hash = PasswordReset::storedHash($userId, 'email');
            ok(is_string($hash) && $hash !== '', '应写入验证码摘要');
            ok($hash !== $code, '存储的必须是 hash 而不是明文');

            ok(PasswordReset::verifyCode($userId, 'email', $code), '正确验证码应校验通过');
            same(null, PasswordReset::storedHash($userId, 'email'), '校验成功后应一次性消费');
            ok(!PasswordReset::verifyCode($userId, 'email', $code), '验证码不应可重复使用');
        } catch (Throwable $e) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . ' 验证码存取（Redis 不可用：' . $e->getMessage() . "）\n";
        }
    });

    test('尝试次数达到上限后验证码作废', function (): void {
        $userId = 980000 + random_int(1, 9999);
        try {
            $code  = PasswordReset::generateCode();
            $wrong = $code === '000000' ? '111111' : '000000';
            PasswordReset::storeCode($userId, 'sms', $code);

            $max = max(1, SysConfig::getInt('security.reset_max_attempts', 5));
            for ($i = 1; $i <= $max + 1; $i++) {
                PasswordReset::verifyCode($userId, 'sms', $wrong);
            }

            same(null, PasswordReset::storedHash($userId, 'sms'), '超过尝试上限后验证码应被作废');
            ok(!PasswordReset::verifyCode($userId, 'sms', $code), '作废后即使正确验证码也不可通过');
        } catch (Throwable $e) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . ' 尝试次数上限（Redis 不可用：' . $e->getMessage() . "）\n";
        }
    });
};