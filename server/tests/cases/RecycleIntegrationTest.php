<?php

declare(strict_types=1);

use plugin\cccms\app\model\User;
use think\facade\Db;

/**
 * 回收站（软删除）集成测试。
 *
 * 验证 think-orm SoftDelete 的三个关键语义，尤其是「唯一键被回收站占用」这个最容易踩的坑：
 *   - 默认查询自动排除已删数据；
 *   - withTrashed() / onlyTrashed() 能取到已删数据；
 *   - 软删后 `username` 唯一键仍被占用 —— 若不在「唯一性校验」里加 withTrashed()，
 *     会误判为「可复用」而撞唯一索引（UserLogic::assertUniqueUsername 正是为此）。
 *
 * 需要数据库；不可用时自动跳过。
 */
return static function (): void {
    suite('回收站（软删除）');

    test('软删后默认查询查不到，withTrashed/onlyTrashed 能查到', function (): void {
        $username = '__itest__recycle_' . time();
        $userId   = (int)Db::name('user')->insertGetId([
            'username' => $username, 'password' => password_hash('test123', PASSWORD_BCRYPT),
            'nickname' => $username, 'status' => 1,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);

        try {
            // 软删除（delete_time 置为当前时间，不物理删除）
            User::destroy($userId);

            ok(User::where('id', $userId)->find() === null, '默认查询应排除已删数据');
            ok(User::withTrashed()->where('id', $userId)->find() !== null, 'withTrashed 应能取到已删数据');
            ok(User::onlyTrashed()->where('id', $userId)->find() !== null, 'onlyTrashed 应只取已删数据');
        } finally {
            // 彻底删除，清理测试数据（raw query 不套软删除）
            Db::name('user')->where('id', $userId)->delete();
        }
    }, true);

    test('软删后唯一键仍被占用（withTrashed 陷阱）', function (): void {
        $username = '__itest__unique_' . time();
        $userId   = (int)Db::name('user')->insertGetId([
            'username' => $username, 'password' => password_hash('test123', PASSWORD_BCRYPT),
            'nickname' => $username, 'status' => 1,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);

        try {
            User::destroy($userId);

            // 正常查询（含软删过滤）查不到，但唯一键仍在回收站里占着
            ok(User::withoutGlobalScope()->where('username', $username)->find() === null, '普通查询应查不到已删账号');
            ok(
                User::withoutGlobalScope()->withTrashed()->where('username', $username)->find() !== null,
                '唯一键仍被回收站中的账号占用'
            );
        } finally {
            Db::name('user')->where('id', $userId)->delete();
        }
    }, true);
};
