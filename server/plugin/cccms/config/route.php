<?php

use plugin\cccms\app\controller\AuthController;
use plugin\cccms\app\controller\ConfigController;
use plugin\cccms\app\controller\DashboardController;
use plugin\cccms\app\controller\CrontabController;
use plugin\cccms\app\controller\DataRuleController;
use plugin\cccms\app\controller\DataScopeTableController;
use plugin\cccms\app\controller\DeptController;
use plugin\cccms\app\controller\DictController;
use plugin\cccms\app\controller\FileController;
use plugin\cccms\app\controller\GeneratorController;
use plugin\cccms\app\controller\LoginLogController;
use plugin\cccms\app\controller\LogController;
use plugin\cccms\app\controller\MaintenanceController;
use plugin\cccms\app\controller\MenuController;
use plugin\cccms\app\controller\NoticeController;
use plugin\cccms\app\controller\OnlineController;
use plugin\cccms\app\controller\PostController;
use plugin\cccms\app\controller\ProfileController;
use plugin\cccms\app\controller\RecycleController;
use plugin\cccms\app\controller\RoleController;
use plugin\cccms\app\controller\UpgradeController;
use plugin\cccms\app\controller\UserController;
use Webman\Route;

// 禁用默认路由（/controller/action 自动路由），只允许显式路由
Route::disableDefaultRoute();

// ---- 认证 ----
Route::get('/ping', [AuthController::class, 'ping']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/captcha', [AuthController::class, 'captcha']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

// ---- 个人中心（登录即可；前端是静态路由，不进左侧菜单） ----
Route::get('/profile', [ProfileController::class, 'index']);
Route::post('/profile/update', [ProfileController::class, 'update']);
Route::post('/profile/password', [ProfileController::class, 'password']);

// ---- 工作台 ----
Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

// ---- 用户 ----
Route::get('/user', [UserController::class, 'index']);
Route::get('/user/read', [UserController::class, 'read']);
Route::post('/user/save', [UserController::class, 'save']);
Route::post('/user/update', [UserController::class, 'update']);
Route::post('/user/delete', [UserController::class, 'delete']);
Route::post('/user/resetPassword', [UserController::class, 'resetPassword']);
// 导入 / 导出（CSV）：导出直接返回文件流，不走统一信封
Route::get('/user/export', [UserController::class, 'export']);
Route::get('/user/template', [UserController::class, 'template']);
Route::post('/user/import', [UserController::class, 'import']);

// ---- 角色 ----
Route::get('/role', [RoleController::class, 'index']);
Route::get('/role/tree', [RoleController::class, 'tree']);
Route::get('/role/read', [RoleController::class, 'read']);
Route::post('/role/save', [RoleController::class, 'save']);
Route::post('/role/update', [RoleController::class, 'update']);
Route::post('/role/delete', [RoleController::class, 'delete']);

// ---- 菜单 ----
Route::get('/menu/tree', [MenuController::class, 'tree']);
Route::get('/menu/userTree', [MenuController::class, 'userTree']);
Route::post('/menu/save', [MenuController::class, 'save']);
Route::post('/menu/update', [MenuController::class, 'update']);
Route::post('/menu/delete', [MenuController::class, 'delete']);

// ---- 部门 ----
Route::get('/dept/tree', [DeptController::class, 'tree']);
Route::post('/dept/save', [DeptController::class, 'save']);
Route::post('/dept/update', [DeptController::class, 'update']);
Route::post('/dept/delete', [DeptController::class, 'delete']);

// ---- 岗位 ----
Route::get('/post', [PostController::class, 'index']);
Route::post('/post/save', [PostController::class, 'save']);
Route::post('/post/update', [PostController::class, 'update']);
Route::post('/post/delete', [PostController::class, 'delete']);

// ---- 字典 ----
Route::get('/dict', [DictController::class, 'index']);
Route::post('/dict/save', [DictController::class, 'save']);
Route::post('/dict/update', [DictController::class, 'update']);
Route::post('/dict/delete', [DictController::class, 'delete']);
Route::get('/dict/data', [DictController::class, 'data']);
Route::post('/dict/saveData', [DictController::class, 'saveData']);
Route::post('/dict/updateData', [DictController::class, 'updateData']);
Route::post('/dict/deleteData', [DictController::class, 'deleteData']);
// 字典分类
Route::get('/dict/category', [DictController::class, 'category']);
Route::post('/dict/category/save', [DictController::class, 'categorySave']);
Route::post('/dict/category/update', [DictController::class, 'categoryUpdate']);
Route::post('/dict/category/delete', [DictController::class, 'categoryDelete']);

// ---- 配置 ----
Route::get('/config', [ConfigController::class, 'index']);
// 前端初始化 / 登录页需要的公开配置（品牌信息 + UI 默认值）
Route::get('/config/ui', [ConfigController::class, 'ui']);
Route::post('/config/save', [ConfigController::class, 'save']);

// ---- 系统维护（顶栏「同步 / 清理缓存」，等价于 menu-sync + perm-scan + 清缓存） ----
Route::post('/system/refresh', [MaintenanceController::class, 'refresh']);

// ---- 数据权限规则 ----
Route::get('/data_rule', [DataRuleController::class, 'index']);
Route::get('/data_rule/options', [DataRuleController::class, 'options']);
Route::get('/data_rule/users', [DataRuleController::class, 'users']);
Route::post('/data_rule/save', [DataRuleController::class, 'save']);
Route::post('/data_rule/update', [DataRuleController::class, 'update']);
Route::post('/data_rule/delete', [DataRuleController::class, 'delete']);
// 受控表（哪些表可以配数据权限）
Route::get('/data_rule/table', [DataScopeTableController::class, 'index']);
Route::post('/data_rule/table/save', [DataScopeTableController::class, 'save']);
Route::post('/data_rule/table/update', [DataScopeTableController::class, 'update']);
Route::post('/data_rule/table/delete', [DataScopeTableController::class, 'delete']);

// ---- 回收站（列表走各模块自己的接口 + trashed=1，这里只有写操作）----
Route::post('/recycle/restore', [RecycleController::class, 'restore']);
Route::post('/recycle/delete', [RecycleController::class, 'delete']);

// ---- 日志 ----
Route::get('/log', [LogController::class, 'index']);
Route::get('/log/export', [LogController::class, 'export']);
Route::post('/log/delete', [LogController::class, 'delete']);

// ---- 登录日志 ----
Route::get('/login_log', [LoginLogController::class, 'index']);
Route::get('/login_log/export', [LoginLogController::class, 'export']);
Route::post('/login_log/delete', [LoginLogController::class, 'delete']);
Route::post('/login_log/clear', [LoginLogController::class, 'clear']);

// ---- 在线用户（Redis 会话索引 + 强制下线） ----
Route::get('/online', [OnlineController::class, 'index']);
Route::post('/online/kick', [OnlineController::class, 'kick']);
Route::post('/online/kickUser', [OnlineController::class, 'kickUser']);

// ---- 通知公告 ----
// 管理侧
Route::get('/notice', [NoticeController::class, 'index']);
Route::get('/notice/read', [NoticeController::class, 'read']);
Route::post('/notice/save', [NoticeController::class, 'save']);
Route::post('/notice/update', [NoticeController::class, 'update']);
Route::post('/notice/delete', [NoticeController::class, 'delete']);
// 阅读侧（登录即可，只看自己的）
Route::get('/notice/my', [NoticeController::class, 'my']);
Route::get('/notice/unread', [NoticeController::class, 'unread']);
Route::post('/notice/markRead', [NoticeController::class, 'markRead']);
Route::post('/notice/markAllRead', [NoticeController::class, 'markAllRead']);

// ---- 附件 ----
Route::get('/file', [FileController::class, 'index']);
Route::post('/file/upload', [FileController::class, 'upload']);
Route::post('/file/delete', [FileController::class, 'delete']);
Route::post('/file/move', [FileController::class, 'move']);
// 附件分类
Route::get('/file/category', [FileController::class, 'category']);
Route::post('/file/category/save', [FileController::class, 'categorySave']);
Route::post('/file/category/update', [FileController::class, 'categoryUpdate']);
Route::post('/file/category/delete', [FileController::class, 'categoryDelete']);

// ---- 定时任务 ----
Route::get('/crontab', [CrontabController::class, 'index']);
Route::get('/crontab/targets', [CrontabController::class, 'targets']);
Route::get('/crontab/logs', [CrontabController::class, 'logs']);
Route::post('/crontab/save', [CrontabController::class, 'save']);
Route::post('/crontab/update', [CrontabController::class, 'update']);
Route::post('/crontab/delete', [CrontabController::class, 'delete']);
Route::post('/crontab/run', [CrontabController::class, 'run']);

// ---- 代码生成器 ----
Route::get('/generator/tables', [GeneratorController::class, 'tables']);
Route::get('/generator/columns', [GeneratorController::class, 'columns']);
Route::post('/generator/preview', [GeneratorController::class, 'preview']);
Route::post('/generator/generate', [GeneratorController::class, 'generate']);

// ---- 自动升级（从上游镜像同步框架代码） ----
Route::get('/upgrade', [UpgradeController::class, 'index']);
Route::get('/upgrade/check', [UpgradeController::class, 'check']);
Route::get('/upgrade/tags', [UpgradeController::class, 'tags']);
Route::post('/upgrade/init', [UpgradeController::class, 'init']);
Route::post('/upgrade/run', [UpgradeController::class, 'run']);

// ---- 代码生成器产出的模块路由（config/route/*.php） ----
foreach (glob(__DIR__ . '/route/*.php') ?: [] as $__routeFile) {
    require $__routeFile;
}
