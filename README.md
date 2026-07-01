<h1 align="center">CCCMS 管理系统</h1>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.0-blue" alt="PHP >= 8.0">
  <img src="https://img.shields.io/badge/ThinkPHP-8.x-green" alt="ThinkPHP 8">
  <img src="https://img.shields.io/badge/Vue-3.x-brightgreen" alt="Vue 3">
  <img src="https://img.shields.io/badge/License-MIT-yellow" alt="License MIT">
</p>

<p align="center">基于 ThinkPHP 8 + Arco Design Vue 的企业级 CMS / 快速开发框架</p>

---

## 项目介绍

CCCMS 是一套高可扩展的企业级管理系统，适合快速二次开发。内置 **5 对象 RBAC 权限体系**、**5 层数据权限过滤**、操作日志、定时任务、字典管理等核心功能。

| 层级 | 组件 | 技术栈 |
|------|------|--------|
| 前端 | `vue-admin/` | Vue 3 + Vite + Arco Design Vue + Axios |
| 应用层 | `cccms-app` | ThinkPHP 8 控制器（PSR-4, `app\` 命名空间） |
| 基础库 | `cccms-library` | Model / Service / 扩展 / 中间件 / 配置 |

### 仓库

- Gitee: <https://gitee.com/svipchao/cccms>
- Github: <https://github.com/svipchao/cccms>
- 官方站点: <http://www.cccms.cc>
- 使用手册: <http://doc.cccms.cc>

---

## 功能一览

### RBAC 权限体系（5 对象模型）

```
用户(User) ──┬── 部门(Dept) ──── 部门角色 ──── 角色(Role) ──── 角色节点(Node)
             ├── 岗位(Post)     ← 身份标签，不参与权限计算
             └── 直连角色 ───────→ 角色(Role) ──── 角色节点(Node)
```

| 对象 | 说明 |
|------|------|
| 用户 | 支持部门关联、直连角色、直连岗位，登录限流 + bcrypt 加密 |
| 部门 | 无限级树形，`auth_range` 控制数据范围（本人/本部门/下属部门） |
| 岗位 | 身份标识，支持排序和启用状态 |
| 角色 | 无限级树形、父子继承，通过 `role_path` 实现 |
| 菜单 | 支持注解自动扫描为权限节点，排序后清除缓存即时生效 |

### 数据权限（5 层过滤）

```
L1 节点权限  → 能不能进这个页面       ← sys_role_node
L2 数据归属  → 能看到谁的数据         ← scopeUserDataAuth (sys_user_dept)
L3 部门范围  → 能看到哪个部门的数据    ← applyDeptAuth
L4 字段权限  → 能看到/改哪些列        ← sys_data_auth (hidden / readonly / mask_show)
L5 行级条件  → 能看到满足条件的行      ← sys_data_auth (condition + 30+ 操作符)
```

数据权限规则支持绑定 **角色 / 部门 / 岗位 / 用户** 四个维度，优先级自动计算（用户 > 岗位 > 部门 > 角色），版本号缓存机制确保高性能。

### 注解驱动开发

控制器方法通过 DocBlock 注解声明权限，零配置即可注册为权限节点：

```
@auth    true    // 是否需要鉴权
@login   true    // 是否需要登录
@encode  json    // 返回编码类型 (view | json | jsonp | xml)
@methods GET|POST // 允许的请求方法
@sort    995     // 节点排序
```

### 配置覆盖机制

基础库的配置文件（`cccms-library/src/cccms/config/*`）可被项目级（`cccms/config/*`）的同名文件覆盖，方便在不修改 vendor 的情况下自定义行为。

### 其他功能

- 操作日志 — 自动记录请求参数、修改详情、返回结果
- 附件管理 — 本地 / 阿里云 OSS / 七牛云 / 腾讯云 COS 存储驱动
- 验证码 — 图形/算术验证码，支持背景图 + 干扰线 + 杂点
- 定时任务 — Cron 表达式执行，白名单保护，执行日志 + 自动清理
- 数据字典 — 类型 + 数据二级结构，统一管理下拉选项
- 系统配置 — 可视化表单配置（switch / select / input-number / textarea 等组件）
- XSS 防护 — 全站请求自动过滤危险标签和事件处理器
- JWT 认证 — 独立密钥配置，Token 刷新机制

---

## 项目结构

```
www/
├── vue-admin/                    # 前端（Vue 3 + Vite）
│   ├── src/
│   │   ├── api/admin/            # 接口封装
│   │   ├── pages/admin/          # 页面组件
│   │   ├── components/           # 公共组件
│   │   ├── router/               # 动态路由（菜单驱动）
│   │   └── stores/               # Pinia 状态管理
│   └── vite.config.js
│
├── cccms/                        # 后端
│   ├── app/                      # 自定义应用模块
│   ├── config/                   # 项目级配置（可覆盖 vendor 默认值）
│   ├── vendor/poetry/
│   │   ├── cccms-library/        # 基础类库
│   │   │   ├── src/
│   │   │   │   ├── Base.php      # 控制器基类（权限拦截）
│   │   │   │   ├── Model.php     # 模型基类（软删除/搜索器/数据权限Scope）
│   │   │   │   ├── Query.php     # 查询扩展（_list/_page/_read/_delete）
│   │   │   │   ├── Service.php   # 服务基类
│   │   │   │   ├── Storage.php   # 文件存储抽象（本地/OSS驱动）
│   │   │   │   ├── model/        # 20+ 数据模型
│   │   │   │   ├── services/     # 业务服务（Auth/User/Node/Config/Captcha/Data）
│   │   │   │   ├── extend/       # 工具类（JWT/Arr/Str/Http/Excel/Ip2Region）
│   │   │   │   ├── support/      # URL / 中间件（Cors/Log/MultiApp/Permission）
│   │   │   │   ├── storages/     # 存储驱动
│   │   │   │   └── cccms/
│   │   │   │       ├── config/   # 默认配置文件
│   │   │   │       └── validate/ # 验证规则
│   │   │   └── docs/             # 数据库结构 + 测试数据
│   │   │
│   │   └── cccms-app/            # 应用控制器
│   │       └── src/
│   │           ├── admin/controller/  # 后台控制器（12个）
│   └           └── index/controller/  # 前台控制器
│
├── public/                       # Web 入口
└── composer.json
```

---

## 快速开始

### 环境要求

- PHP >= 8.0
- MySQL >= 8.0（或 MariaDB >= 10.3）
- Composer 2.x
- Node.js >= 16（前端开发）

### 安装步骤

```bash
# 1. 克隆项目
git clone https://gitee.com/svipchao/cccms.git
cd cccms

# 2. 安装依赖
composer update

# 3. 导入数据库
# 执行 doc/完整数据库结构.sql
# 可选：执行 vendor/poetry/cccms-library/docs/cccms-demo-data.sql 导入测试数据

# 4. 配置数据库连接
# 编辑 .env 或 config/database.php

# 5. 配置网站根目录为 /public
# Nginx 伪静态参考：
#   location / { try_files $uri $uri/ /index.php?$query_string; }
# Apache 已内置 .htaccess

# 6. 启动后端（PHP 内置服务器用于开发）
php think run

# 7. 启动前端（开发模式）
cd vue-admin
npm install
npm run dev
```

### 默认账号

| 账号 | 密码 | 说明 |
|------|------|------|
| `admin` | `admin` | 超级管理员（首次登录后建议立即修改） |

测试数据中所有用户密码统一为 `123456`。

### 配置说明

| 配置文件 | 作用 |
|----------|------|
| `.env` | 数据库连接、App Key |
| `cccms/config/data_auth.php` | 数据权限-可选目标表列表（覆盖 vendor 默认值） |
| `cccms/config/jwt.php` | JWT 密钥（生产环境务必修改） |
| `cccms/config/cccms.php` | 定时任务白名单、中间件、应用名称 |

---

## 后端开发指南

### 新增控制器

```php
<?php
namespace app\admin\controller;
use cccms\Base;

/**
 * 我的模块
 * @sort 990
 */
class Demo extends Base
{
    public function init(): void
    {
        $this->model = \cccms\model\SysUser::mk();
    }

    /**
     * 列表
     * @auth true
     * @login true
     * @encode json
     * @methods GET
     */
    public function index(): void
    {
        $data = $this->model->_page();
        _result(['code' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
```

注解中的 `@sort` 控制菜单排序，`@auth`/`@login`/`@methods` 自动生成权限节点。刷新后台即可在菜单管理中看到新节点。

### 参数验证

```php
// 格式：方法.表名.是否包含所有字段
//      必选参数|可选参数
$params = _validate('post.sys_user.true', 'nickname,username,password|dept,post_ids');
```

### 快捷查询

```php
$this->model->_withSearch('username,status', $params)->_page($params);
$this->model->_list(['recycle' => true]);
$this->model->_delete($id, $type);  // null=软删除, 'delete'=真删, 'restore'=恢复
```

---

## 感谢

- [ThinkPHP](https://www.thinkphp.cn)
- [Arco Design Vue](https://github.com/arco-design/arco-design-vue)
- [Remixicon](https://remixicon.cn/)
- [ThinkAdmin](https://gitee.com/zoujingli/ThinkAdmin)
- [FastAdmin](https://gitee.com/karson/fastadmin)

## 许可证

[MIT License](LICENSE)
