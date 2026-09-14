<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\SoftDelete;
use plugin\cccms\support\UserContext;
use think\facade\Db;

/**
 * 个人中心：登录用户查看 / 维护**自己**的资料。
 *
 * 与 UserLogic 的区别：这里始终以 `$user->id` 为目标，不接受外部传入的 id，
 * 因此不需要数据权限判定（不会出现「自定义规则把自己也挡掉」的尴尬），
 * 也不会因为误传 id 改到别人。
 */
final class ProfileLogic
{
    /**
     * 允许本人修改的字段（白名单）。
     *
     * 刻意不放开 username / status / 角色 / 部门 / 岗位——那些属于用户管理，
     * 必须在 `cccms:user:update` 权限下由管理员操作。
     */
    private const FIELDS = ['nickname', 'avatar', 'email', 'phone'];

    public static function read(UserContext $user): array
    {
        $row = SoftDelete::apply(Db::name('user'))
            ->where('id', $user->id)
            ->field('id,username,nickname,avatar,email,phone,status,remark,login_time,login_ip,create_time')
            ->find();

        if (!$row) {
            throw new ApiException('账号不存在或已失效', 404);
        }

        $row['roles'] = self::roleNames($user->id);
        $row['depts'] = self::deptNames($user->id);

        return $row;
    }

    public static function update(UserContext $user, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        if (array_key_exists('nickname', $data)) {
            $nickname = trim((string)$data['nickname']);
            if ($nickname === '') {
                throw new ApiException('昵称不能为空', 422);
            }
            $data['nickname'] = mb_substr($nickname, 0, 64);
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string)$data['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException('邮箱格式不正确', 422);
            }
            $data['email'] = mb_substr($email, 0, 128);
        }

        if (array_key_exists('phone', $data)) {
            $phone = trim((string)$data['phone']);
            if ($phone !== '' && !preg_match('/^[0-9+\-\s]{5,32}$/', $phone)) {
                throw new ApiException('手机号格式不正确', 422);
            }
            $data['phone'] = $phone;
        }

        if (array_key_exists('avatar', $data)) {
            $data['avatar'] = mb_substr(trim((string)$data['avatar']), 0, 255);
        }

        if ($data === []) {
            return;
        }

        Db::name('user')->where('id', $user->id)->update($data);
    }

    public static function changePassword(UserContext $user, string $old, string $new): void
    {
        if ($old === '' || $new === '') {
            throw new ApiException('请输入原密码与新密码', 422);
        }
        PasswordPolicy::assertValid($new);

        $hash = (string)Db::name('user')->where('id', $user->id)->value('password');
        if ($hash === '' || !password_verify($old, $hash)) {
            throw new ApiException('原密码不正确', 422);
        }
        if ($old === $new) {
            throw new ApiException('新密码不能与原密码相同', 422);
        }

        Db::name('user')->where('id', $user->id)->update([
            'password' => password_hash($new, PASSWORD_BCRYPT),
        ]);
    }

    /** 我拥有的角色名（含继承来的祖先角色，与鉴权口径一致） */
    private static function roleNames(int $userId): array
    {
        $ids = AuthService::effectiveRoleIds($userId);
        if ($ids === []) {
            return [];
        }

        return array_values(SoftDelete::apply(Db::name('role'))
            ->whereIn('id', $ids)
            ->order('sort', 'asc')
            ->column('name'));
    }

    private static function deptNames(int $userId): array
    {
        $ids = array_map('intval', Db::name('user_dept')->where('user_id', $userId)->column('dept_id'));
        if ($ids === []) {
            return [];
        }

        return array_values(SoftDelete::apply(Db::name('dept'))
            ->whereIn('id', $ids)
            ->order('sort', 'asc')
            ->column('name'));
    }
}
