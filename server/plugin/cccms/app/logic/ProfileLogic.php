<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\UserContext;

/**
 * 个人中心：登录用户查看 / 维护**自己**的资料。
 *
 * 与 UserLogic 的区别：这里始终以 `$user->id` 为目标，不接受外部传入的 id，
 * 因此不需要数据权限判定，也不会因为误传 id 改到别人。
 *
 * 这里**统一用 `User::withoutGlobalScope()` 显式跳出数据权限**：
 * 「本部门」档且自己没被分配任何部门时，预设基线是 fail-closed（查不到任何用户），
 * 若走作用域，用户会连自己的资料都读不到、改不了。
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
        $row = User::withoutGlobalScope()
            ->where('id', $user->id)
            ->field('id,username,nickname,avatar,email,phone,status,remark,login_time,login_ip,create_time')
            ->find();

        if (!$row) {
            throw new ApiException('账号不存在或已失效', 404);
        }

        $data          = $row->toArray();
        $data['roles'] = self::roleNames($user->id);
        $data['depts'] = self::deptNames($user->id);

        return $data;
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

        User::withoutGlobalScope()->where('id', $user->id)->update($data);
    }

    public static function changePassword(UserContext $user, string $old, string $new): void
    {
        if ($old === '' || $new === '') {
            throw new ApiException('请输入原密码与新密码', 422);
        }
        PasswordPolicy::assertValid($new);

        $hash = (string)User::withoutGlobalScope()->where('id', $user->id)->value('password');
        if ($hash === '' || !password_verify($old, $hash)) {
            throw new ApiException('原密码不正确', 422);
        }
        if ($old === $new) {
            throw new ApiException('新密码不能与原密码相同', 422);
        }

        User::withoutGlobalScope()->where('id', $user->id)->update([
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

        return array_values(Role::whereIn('id', $ids)->order('sort', 'asc')->column('name'));
    }

    /** 我所属的部门名 */
    private static function deptNames(int $userId): array
    {
        $ids = array_map('intval', UserDept::where('user_id', $userId)->column('dept_id'));
        if ($ids === []) {
            return [];
        }

        // 部门模型参与数据权限：这里必须显式跳出（否则「本部门」档未分配部门时读不到自己的部门名）
        return array_values(Dept::withoutGlobalScope()
            ->whereIn('id', $ids)
            ->order('sort', 'asc')
            ->column('name'));
    }
}
