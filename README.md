# CCCMS

> 企业级后台管理系统 / 快速开发框架 —— **全新工程**

本文档为**需求与设计定稿**，可直接据此实施。

---

## 一、项目定位

### 1.1 目标

一套插件化、注解驱动、fail-closed 的后台管理系统底座，适合作为中后台项目的起点，也适合快速二次开发。

### 1.2 明确不做的事

| 不做 | 说明 |
|------|------|
| 不迁移老 cccms 数据 | 老项目（ThinkPHP 8）保持原样，本工程为全新库，不复用其库表数据 |
| 不重写 / 不兼容老 cccms | 不提供 DocBlock→属性 的转换工具，不保留 `@auth/@login/@methods/@encode/@sort` 旧注解 |
| 不照搬参考项目代码 | 仅借鉴思路：插件化目录（SaiAdmin）、功能范围与数据权限模型（老 cccms） |
| 不用 DocBlock 注解 | 全部改为 PHP 8 原生属性（Attribute），**只有一种声明机制** |

### 1.3 技术栈（已确认）

| 层 | 技术 |
|----|------|
| 后端 `server/` | PHP >= 8.1、Webman 2.x、**think-orm**、MySQL 8、Redis |
| 前端 `frontend/` | **Vue 3 + Vite + TypeScript** + **Element Plus** + **Tailwind CSS 4** + Pinia + Axios + ECharts |
| 鉴权 | JWT（accessToken），Redis 存会话/黑名单 |
| 后端目录形态 | Webman 插件化（`plugin/*`） |
| 响应格式 | 统一出口 `Result`，支持 **json / jsonp / xml / view** 四种编码，默认 `json` |

---

## 二、目录结构

```
cccms/
├── server/                          # 后端（Webman）
│   ├── app/                         # 主应用（仅入口与全局配置）
│   ├── config/
│   │   └── plugin/
│   │       └── cccms/               # 插件配置覆盖（数据库/Redis/中间件/路由/响应）
│   ├── plugin/
│   │   ├── cccms/                   # ★ 后台基础功能插件（核心）
│   │   │   ├── app/
│   │   │   │   ├── controller/      # Auth/User/Role/Menu/Dept/Post/Dict/Config/Log/File/Crontab/Generator
│   │   │   │   ├── middleware/      # Cors / Xss / CheckLogin / CheckAuth / ResponseEncode / OperationLog
│   │   │   │   ├── model/           # think-orm 模型
│   │   │   │   └── logic/           # 业务逻辑层
│   │   │   ├── basic/               # BaseController
│   │   │   ├── support/
│   │   │   │   ├── attribute/       # Permission / NoAuth / NoLogin / Restrict
│   │   │   │   ├── storage/         # StorageDriver + Local / Oss / Cos / Qiniu
│   │   │   │   ├── PermissionMeta / AuthService / TokenService / UserContext
│   │   │   │   ├── Result / ApiException / ExceptionHandler
│   │   │   │   ├── DataScope / Cipher / FileStorage
│   │   │   │   ├── CrontabTask / CrontabRunner / CronMatcher
│   │   │   │   └── PermScanner / ControllerScanner
│   │   │   ├── command/             # perm-scan / menu-sync；task/ 下为定时任务类
│   │   │   ├── config/              # app / middleware / route / route/ / exception / response / auth / filesystem / database / bootstrap / process
│   │   │   ├── db/                  # schema.sql / seed.sql / menu.php
│   │   │   └── process/             # Crontab 调度进程
│   │   └── index/                   # 前台应用插件（公开路由 /site/*）
│   │       ├── app/controller/
│   │       └── config/              # app / middleware / route
│   ├── public/
│   │   └── storage/                 # 本地附件目录（同域访问 /storage/...，禁止解析 PHP）
│   ├── composer.json
│   └── webman
│
├── frontend/                        # 前端（Vue 3 + Vite + TS + Element Plus）
│   ├── src/
│   │   ├── api/                     # 接口封装（request.ts 为统一出口，负责鉴权头/错误提示/401 跳转）
│   │   ├── assets/styles/
│   │   │   ├── index.css            # Tailwind + preflight + token → 工具类映射
│   │   │   ├── tokens.css           # ★ 设计 token（亮/暗两套，全站唯一色值来源）
│   │   │   ├── element.css          # Element Plus 变量覆盖（组件随主题自动切换）
│   │   │   └── app.css              # 业务全局类 / 滚动条 / 路由过渡 / NProgress
│   │   ├── components/core/         # ArtTable（列表页骨架）/ ArtIcon / ArtSettingsDrawer
│   │   ├── composables/useTable.ts  # 列表页通用逻辑（查询/分页/加载/多选）
│   │   ├── directives/index.ts      # v-auth 按钮级权限指令
│   │   ├── layouts/
│   │   │   ├── BasicLayout.vue      # 外壳：侧边栏 + 顶栏 + 标签栏 + 内容区
│   │   │   └── components/          # LayoutSidebar / LayoutHeader / LayoutTagbar / LayoutContent
│   │   ├── pages/                   # 页面（按插件分目录，与 server/plugin 对应）
│   │   │   └── cccms/               #   ↳ 对应 server/plugin/cccms（含静态页）
│   │   │       ├── user/index.vue   #   ↳ 对应 .../app/controller/UserController.php
│   │   │       ├── login/index.vue  # 登录（静态路由 /login）
│   │   │       ├── dashboard/index.vue  # 仪表盘（静态路由 /dashboard）
│   │   │       ├── error/404.vue    # 404（静态路由）
│   │   │       └── ...              #   其余每个模块一个目录
│   │   ├── router/index.ts          # 动态路由（菜单驱动）+ NProgress + 登出清理
│   │   ├── stores/                  # user（登录态）/ menu（菜单）/ setting（主题）/ worktab（标签页）
│   │   ├── types/table.ts           # 列表页列配置类型
│   │   └── utils/                   # auth / crypto / icon（图标注册表）/ theme（主题运行时）
│   ├── vite.config.ts
│   └── package.json
│
├── docs/                            # 参考资料（老 cccms / SaiAdmin 源码，只读）
└── README.md
```

**约定**：`plugin/cccms` 只放**框架级基础功能**；一切业务功能一律新建业务插件，禁止往 `cccms` 插件里塞业务代码。

---

## 三、注解规格（唯一声明机制）

### 3.1 决策一：权限节点标识 = 显式 slug

**定稿：`#[Permission]` 中显式声明 slug，不采用「控制器路径派生」方案。**

**命名约定**：`{插件}:{模块}:{动作}`，**至少 3 段**，全小写。
**正则**：`^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*){2,}$`

第一段固定为**插件名**，因此「权限标识 / 后端控制器 / 前端页面」三者可以互相推导：

| 层 | 位置 | 示例 |
|---|---|---|
| 权限标识 | `#[Permission(slug: ...)]` | `cccms:user:index` |
| 后端控制器 | `server/plugin/{插件}/app/controller/{模块}Controller.php` | `plugin/cccms/app/controller/UserController.php` |
| 前端页面 | `frontend/src/pages/{插件}/{模块}/index.vue` | `pages/cccms/user/index.vue` |
| 路由地址 | `sys_menu.path` | `/cccms/user` |

> 新增业务插件时同理：`plugin/sales/…` ↔ `pages/sales/…` ↔ `sales:order:index`。

**段数规则**：**最后一段是动作，其之前的全部段拼起来 = 归属菜单的 slug**（按最长前缀匹配）。

| 示例 slug | 动作 | 归属菜单 |
|---|---|---|
| `cccms:user:index` | `index` | `cccms:user` |
| `cccms:user:save` | `save` | `cccms:user` |
| `sales:order:item:list` | `list` | `sales:order:item` |

| 项 | 取值 |
|----|------|
| 存储位置 | `sys_menu.node`（节点树）、`sys_role_node.node`（角色授权） |
| 前端用法 | `v-auth="'cccms:user:save'"` |
| 唯一性 | 全局唯一，由扫描命令强制校验 |

理由：与路由解耦（改路由不影响已授权数据）、可全文检索、前后端同一字符串。

### 3.2 注解总览：4 个属性

文件位置：`server/plugin/cccms/support/attribute/`，每个属性一个文件。

```php
use plugin\cccms\support\attribute\{Permission, NoAuth, NoLogin, Restrict};
```

| 属性 | 文件 | 可写位置 | 参数 | 类型 | 必填 | 取值 | 默认 |
|---|---|---|---|---|---|---|---|
| `Permission` | `Permission.php` | 方法 | `slug` | string | ✅ | 见 §3.1 格式 | — |
| | | | `title` | string | ✅ | 按钮名称（写入 `sys_menu.title`） | — |
| | | | `sort` | int | ❌ | 按钮排序 | `0` |
| | | | `group` | string\|null | ❌ | 显式指定归属菜单 slug；仅当前缀推断不成立时使用 | `null` |
| `NoAuth` | `NoAuth.php` | 方法 | `title` | string\|null | ❌ | 操作名，写进操作日志 `sys_log.title` | `null` |
| `NoLogin` | `NoLogin.php` | 类 / 方法 | `title` | string\|null | ❌ | 同上 | `null` |
| `Restrict` | `Restrict.php` | 类 / 方法 | `methods` | string[] | ❌ | `GET` `POST` `PUT` `DELETE` `PATCH` | `[]` = 不校验 |
| | | | `encode` | string[]\|null | ❌ | `json` `jsonp` `xml` `view` | `null` = 全局默认 `['json']` |

**强制写法**：一律使用**命名参数**（`slug:` / `title:` / `methods:` / `encode:`），禁止位置参数。

#### 合法写法

```php
#[Permission(slug: 'cccms:user:index', title: '用户列表')]
#[Permission(slug: 'cccms:user:index', title: '用户列表', sort: 10)]
#[Permission(slug: 'sales:order:item:list', title: '订单明细', group: 'sales:order')]
#[NoAuth]
#[NoAuth(title: '修改密码')]
#[NoLogin]
#[NoLogin(title: '登录')]
#[Restrict(methods: ['POST'])]
#[Restrict(encode: ['json', 'jsonp'])]
#[Restrict(methods: ['POST'], encode: ['json'])]
```

#### 非法写法（扫描命令直接报错）

| 写法 | 问题 |
|---|---|
| `#[Permission('cccms:user:index', '用户列表')]` | 位置参数，易错序 |
| `#[Permission(slug: 'Cccms:User:Index', title: 'x')]` | 必须全小写 |
| `#[Permission(slug: 'user:index', title: 'x')]` | 不足 3 段 |
| `#[Restrict(methods: ['post'])]` | HTTP 方法必须大写 |
| `#[Restrict(encode: ['html'])]` | `encode` 取值非法 |
| 同一方法上 `#[NoAuth]` + `#[Permission]` | 互斥 |

### 3.3 各属性完整定义

```php
// 文件：server/plugin/cccms/support/attribute/Permission.php
namespace plugin\cccms\support\attribute;

use Attribute;

/** 业务接口：需登录 + 需权限 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Permission
{
    public function __construct(
        public readonly string  $slug,
        public readonly string  $title,
        public readonly int     $sort  = 0,
        public readonly ?string $group = null,
    ) {}
}
```

```php
// 文件：server/plugin/cccms/support/attribute/NoAuth.php
namespace plugin\cccms\support\attribute;

use Attribute;

/**
 * 登录即可，无需按钮权限（如个人资料）。
 *
 * 不是权限点（不产生按钮节点），但可以带一个 $title 作为操作名：
 * 操作日志按注解取可读名称，否则这类接口在日志里只会显示「—」。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class NoAuth
{
    public function __construct(public readonly ?string $title = null) {}
}
```

```php
// 文件：server/plugin/cccms/support/attribute/NoLogin.php
namespace plugin\cccms\support\attribute;

use Attribute;

/**
 * 完全匿名（登录页、验证码）。类级生效于整个控制器。
 *
 * 同 NoAuth：$title 只用于操作日志的操作名。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NoLogin
{
    public function __construct(public readonly ?string $title = null) {}
}
```

```php
// 文件：server/plugin/cccms/support/attribute/Restrict.php
namespace plugin\cccms\support\attribute;

use Attribute;

/** 可选加固：约束请求方法与响应编码 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Restrict
{
    /**
     * @param string[]      $methods 允许的 HTTP 方法；[] = 不校验（由路由决定）
     * @param string[]|null $encode  允许的响应编码；null = 用全局默认
     */
    public function __construct(
        public readonly array  $methods = [],
        public readonly ?array $encode  = null,
    ) {}
}
```

#### 完整控制器示例

```php
namespace plugin\cccms\app\controller\system;

use plugin\cccms\app\controller\BaseController;
use plugin\cccms\support\attribute\{Permission, NoAuth, NoLogin, Restrict};

#[NoLogin]                                   // 类级：整个控制器匿名
class AuthController extends BaseController
{
    public function login(Request $request): Response {}     // 匿名
    public function captcha(Request $request): Response {}   // 匿名
}

class UserController extends BaseController
{
    #[Permission(slug: 'cccms:user:index', title: '用户列表')]
    #[Restrict(encode: ['json', 'xml'])]
    public function index(Request $request): Response {}

    #[Permission(slug: 'cccms:user:save', title: '用户保存')]
    #[Restrict(methods: ['POST'], encode: ['json'])]
    public function save(Request $request): Response {}

    #[NoAuth]                                                // 登录即可，无需授权
    public function profile(Request $request): Response {}

    public function export(Request $request) {}              // ← 无属性 = 拒绝（fail-closed）
}
```

### 3.4 `#[Restrict]` 运行流程与定义标准

`#[Restrict]` 不参与「能不能访问」，只约束「请求形状」和「输出编码」，因此放在**鉴权通过之后、控制器之前**。

```
CheckLogin → 401
  ↓
CheckAuth  ├─ ① 认证：未登录 → 401
           ├─ ② 授权：slug 不匹配 → 403
           └─ ③ 请求方法：不在 methods 白名单 → 405
  ↓
ResponseEncode ├─ ④ 响应编码：不在 encode 白名单 → 406
  ↓
Controller → Logic → Model
  ↓
Result（按选定编码渲染 json / jsonp / xml / view）
```

顺序刻意设计为 **401 → 403 → 405 → 406**，避免越权者靠错误码探测接口结构。

反射阶段（进程内缓存，每进程只算一次）：

```php
$attr = $method->getAttributes(Restrict::class)[0]
     ?? $class->getAttributes(Restrict::class)[0] ?? null;
$r = $attr?->newInstance();

$meta['methods'] = $r?->methods ?? [];      // ['POST'] 或 []
$meta['encode']  = $r?->encode;             // ['json','xml'] 或 null
```

判定阶段：

```php
// ③ 请求方法
if ($meta['methods'] !== [] && !in_array($request->method(), $meta['methods'], true)) {
    throw new ApiException("请求方法不允许：{$request->method()}", 405);
}

// ④ 响应编码：请求侧 ?format=xxx，缺省用白名单第一项
$allow = $meta['encode'] ?? config('plugin.cccms.response.default', ['json']);
$want  = $request->input('format') ?: $allow[0];
if (!in_array($want, $allow, true)) {
    throw new ApiException("响应编码不允许：{$want}", 406);
}
```

**`encode` 语义定稿**：

| 编码 | 输出 | 附加要求 |
|---|---|---|
| `json` | `application/json` | — |
| `jsonp` | `text/javascript` 包裹 | 请求必须带 `callback`，否则 400 |
| `xml` | `application/xml` | 数组转 XML |
| `view` | 渲染 `plugin/*/view/*.php` 模板 | 模板不存在 → 500 |

- 请求侧选择：`?format=jsonp`（无该参数 → 取白名单第一项，通常为 `json`）
- 不在白名单 → **406**（fail-closed，静默回退会掩盖配置错误）

**定义标准（唯一标准，不写也合法）**：

| 场景 | 写不写 `#[Restrict]` |
|---|---|
| 路由已显式声明方法（`Route::post()`），且只用 json | **不写**（默认） |
| 路由用 `Route::any()` / `Route::add()` 收多方法 | **写 `methods`**，收紧到实际允许的方法 |
| 写操作（save/delete/status）需二次加固 | **写 `methods`**，如 `['POST']` |
| 接口需支持 jsonp / xml / view | **写 `encode`**，如 `['json','jsonp']` |
| 整个控制器统一约束 | **写在类上**，方法级可覆盖 |

优先级：**方法级 > 类级 > 默认（`[]` / `null`）**。

### 3.5 鉴权规则矩阵（唯一一张规则表）

| 方法上的属性 | 需登录 | 需权限 | 说明 |
|---|---|---|---|
| 无任何属性 | — | — | **拒绝**（HTTP 500 + 提示「接口未声明权限」，fail-closed） |
| `#[Permission]` | ✅ | ✅ 校验 slug | 默认业务接口 |
| `#[NoAuth]` | ✅ | ❌ | 登录即可 |
| `#[NoLogin]` | ❌ | ❌ | 匿名（类级 / 方法级） |
| `#[Restrict]` | 由其它属性决定 | 由其它属性决定 | 只追加约束（405 / 406），不改变鉴权语义 |

互斥校验（扫描命令强制）：`#[Permission]` / `#[NoAuth]` / `#[NoLogin]` **三者至多出现一个**；类级 `#[NoLogin]` 的类内**不得**出现 `#[Permission]`。

元数据解析顺序（单一流程）：

```
1. 类级 #[NoLogin]        → noLogin = true（且类内禁止 #[Permission]）
2. 方法级 #[NoLogin]      → noLogin = true（顺带取 title 作为操作名）
3. 方法级 #[NoAuth]       → noAuth  = true（顺带取 title 作为操作名）
4. 方法级 #[Permission]   → slug / title / sort / group
5. 以上都没有             → fail-closed（500）
6. methods = 方法级 #[Restrict] ?? 类级 #[Restrict] ?? []
   encode  = 方法级 #[Restrict] ?? 类级 #[Restrict] ?? null
```

### 3.6 角色模型：超管与继承（已确认）

#### 超管

**超管 = 持有 `sys_role.code = 'super_admin'` 的角色。** 不使用 `id === 1` 硬编码。

配套约束：该角色 `code` 禁止编辑、禁止删除；`sys_role.code` 建唯一索引；初始化种子固定写入。超管绕过权限校验与数据权限，且不写入权限缓存。

#### 角色继承（**方案 A：子角色继承父角色**）

**父角色 = 权限基线，子角色 = 基线 + 自身增量。**

```
角色树                      实际可用节点
├── 销售（基线）             order:index, order:read
│   ├── 销售主管             ↑ 全部 + order:audit, order:export
│   └── 销售实习生           ↑ 全部（不含审核）
```

| 规则 | 说明 |
|---|---|
| 节点来源 | 子角色可用节点 = **自身节点 ∪ 祖先链节点**（`sys_role.parent_id` 向上递归） |
| 新增子角色 | **不影响父角色**（与「父叠加子」方案的关键差异，无隐式扩权） |
| `data_scope` 继承 | 子角色未显式设置（`NULL`）时取父角色，否则用自身 |
| 授权界面 | 继承来的节点显示为「继承（只读）」，不可取消，只能去父角色改 |
| 防环 | 保存时强制**环检测** + **深度上限 5** |
| 缓存失效 | 改父角色节点 → 所有后代角色的用户缓存失效（由全局版本号自然覆盖） |

### 3.7 决策五：缓存与失效矩阵

| 缓存对象 | 存储 | 失效方式 |
|---|---|---|
| 属性元数据（slug/免登录/methods/encode） | 进程内静态数组 | 部署 reload 自动失效 |
| 用户权限集合 | Redis `cccms:perm:u:{uid}:{ver}` | 全局版本号 `cccms:perm:version`，变更时 `INCR` |
| 菜单树 / 节点树 | Redis `cccms:menu:{ver}` | 同上版本号机制 |
| 数据权限规则 | Redis `cccms:data:{uid}:{ver}` | 同上版本号机制 |
| 用户令牌 | Redis `cccms:token:{uid}:{jti}` | 禁用/改密/删除用户 → 删 key，立即失效 |
| 超管 | 不缓存 | — |

**变更 → 版本号自增**，从而一次性失效所有相关缓存：

- 角色节点（`sys_role_node`）变更，或角色继承关系（`sys_role.parent_id`）变更
- 用户角色（`sys_user_role`）变更
- 菜单（`sys_menu`）新增/修改/删除
- 数据权限（`sys_data_rule`、`sys_role.data_scope`）变更
- `perm-scan --sync` 执行后

> 版本号方案取代「逐 key 删除」，避免老 cccms 中「改了角色权限不生效」的问题。

### 3.8 决策六：菜单树与权限节点——单一来源

**`sys_menu` 表是运行时唯一权威的「菜单 + 权限节点」树**，由两段声明生成，职责不重叠：

| 节点类型 | 声明位置 | 内容 |
|---|---|---|
| type=1 目录 / type=2 菜单 | `plugin/cccms/db/menu.php` **手写** | title / icon / path / component / sort / slug |
| type=3 按钮 | **控制器 `#[Permission]` 自动生成** | slug / title / sort（均来自注解） |

**目录分组**：一级目录用于分类，当前两组 —— **权限配置 / 系统设置**；**工作台**与之并列，本身就是一级菜单（不再套「控制台」目录）。

- 目录 slug 用 `插件名:分组名`（`cccms:perm` / `cccms:setting`）；
- 分组名**刻意不与任何模块名重复**，否则下面的「最长前缀」规则可能把 `cccms:xxx:动作` 误挂到目录上；
- 「工作台」是静态路由（`/dashboard`），菜单项的 `component` 留空 → 不注册动态路由，只作为菜单入口；
- **「个人中心」是纯静态路由**（`/profile`，写在 `router/index.ts`，`sys_menu` 里没有这一行）→ 左侧菜单不会出现它，入口在右上角用户下拉；接口用 `#[NoAuth]`（登录即可，不产生按钮节点），因为「看/改自己的资料」不该挂在任何权限点上；
- 调整分类：把 `db/menu.php` 里的节点整体挪到另一个目录的 `children`，再执行 `php webman cccms:menu-sync`（该命令会更新 `parent_id` / `path` / `sort`）；
- 删除节点：在 `db/menu.php` 里补 `['slug' => 'xxx', 'remove' => true]`，`menu-sync` 会连带删除该节点（含子节点）与角色授权（幂等，可重复执行）。

**按钮挂载规则**：按 **slug 最长前缀**匹配菜单节点。

1. 取 `#[Permission].group`（若显式指定）优先；
2. 否则从 slug 尾部逐段回退，找到第一个存在于 `sys_menu` 的菜单节点作为父级。

例：`cccms:user:index` → 先试 `cccms:user` → 命中，挂载；`sales:order:item:list` → 先试 `sales:order:item`，未命中则 `sales:order`，以此类推。

找不到父级菜单 → **扫描报错**（否则该按钮无从勾选，普通管理员永远 403），此时用 `group:` 显式指定。

因此：**只有需要展示信息的目录/菜单才手写，按钮零重复声明。**

已存在的按钮节点如果归属变了（改了 `group`、或原来的父菜单被删掉/挪走），`perm-scan` 会把它**纠正到新的父节点**下：
否则它会挂在一个已删除的节点下 —— 行还在，但在角色授权树里根本看不见，管理员无法勾选，表现为「权限静默失效」。
`title` / `sort` 依旧不覆盖，后台界面的人工调整仍然是安全的。

不需要授权、只是登录即可的接口，用 `#[NoAuth]`，不产生按钮节点。

这类接口不是权限点，所以没有 `title` 可写，但**操作日志是按注解取操作名的**（`sys_log.title`）——
漏了 `title` 的话，日志里 `/auth/login` 这类记录的操作名就是空的「—」。
所以写操作的 `#[NoAuth]` / `#[NoLogin]` 要顺手补上操作名，例如 `#[NoLogin(title: '登录')]`、`#[NoAuth(title: '注销')]`。

### 3.9 软删除与回收站

**删 ≠ 消失**：业务主数据的删除都是软删除（写 `delete_time`），在**各模块页面自己的表格里**恢复或彻底删除。

回收站既不是独立页面、也不是独立接口 —— 点表格右上角的回收站图标，**同一张表换成查已删数据**
（列表接口带 `trashed=1`），操作列换成「还原 / 彻底删除」，列完全不用动：

```vue
<template #toolbar-right>
  <RecycleToggle :active="recycle" label="用户" @toggle="toggle" />
</template>
```

前端三个小件就够了：

| 件 | 作用 |
|---|---|
| `useRecycle(type, { reload, ids?, clear? })` | 开关 + 还原 / 彻底删除（`v-auth` 受 `cccms:recycle:restore` / `cccms:recycle:delete` 控制） |
| `ArtTable` 的 `recycle` 属性 | 隐藏页面自己的「操作」列（约定 `slot: 'action'`）、顶部换成「回收站模式」+ 批量按钮，并统一渲染「还原 / 彻底删除」列 |
| `RecycleToggle` | 表格右上角的开关，激活时高亮 |

后端只在 `SoftDelete::listQuery($table, $params)` 一处收口：`trashed` 为空走 `apply()`（排除已删），
为 `1` 走 `onlyTrashed()`（只看已删）。**树形数据（部门 / 菜单 / 分类）在回收站里返回平铺列表**，
因为已删节点的父节点可能还活着，硬拼树会把「父未删、子已删」的节点直接漏掉，反而恢复不了。

⚠️ `useRecycle` 必须写在 `useTable` **之前**：列表闭包在 setup 阶段就会执行一次，
而它要读 `recycle.value`（否则撞上 const 的暂时性死区）。

各页面的回收站入口：用户 / 角色 / 部门 / 岗位 / 菜单 / 定时任务 / 数据权限规则 / 附件 / 字典类型；
此外字典数据（字典数据抽屉内）、字典分类与附件分类（左侧分类树，节点操作换成还原 / 彻底删除）各有一个。

| 处理 | 表 |
|---|---|
| **软删除 + 回收站** | `user` / `role` / `dept` / `post` / `dict_type` / `dict_data` / `category` / `crontab` / `data_rule` / `file` / `menu` |
| **不软删** | 关联表（`user_role` / `user_dept` / `user_post` / `role_node` / `dept_role`）、`log`（走保留天数清理）、`config`（无删除入口） |

实现：`support/SoftDelete`（查询构造器版）。项目业务层统一用 `Db::name()`，用不了模型的
`SoftDelete` trait；但 Query 本身支持 `useSoftDelete`（Builder 补条件、`delete()` 改写成 UPDATE），
所以封成 `apply()` / `onlyTrashed()` / `remove()` / `restore()` / `force()`。

```php
SoftDelete::listQuery('user', $params)         // 列表入口：按 trashed 参数自动选下面两者
SoftDelete::apply(Db::name('user'))            // 普通查询：排除已删除
SoftDelete::onlyTrashed(Db::name('user'))      // 回收站：只看已删除
SoftDelete::remove(Db::name('user'), $id)      // 软删除
SoftDelete::restore(Db::name('user'), $id)     // 恢复
SoftDelete::force(Db::name('user'), $id)       // 彻底删除
```

几条刻意的取舍：

- **软删除是显式的**：每个读取入口都要记得 `apply()`。已覆盖的关键路径包括
  登录与鉴权（`AuthService`，软删用户立即 401、软删角色不再授权/超管）、数据权限引擎（`DataScope`）、
  定时任务调度进程（`process/Crontab`）、以及各模块的列表 / 树 / 下拉候选。
- **关联表保留**：删用户/角色/部门/岗位时不再级联物理删关联数据，恢复后角色、部门、岗位、授权原样回来；
  同时软删的主体在鉴权侧一律被过滤，不会「删了还在授权」。
- **唯一键不做特例**：`user.username` / `role.code` / `post.code` / `dict_type.type` 软删后仍占用该值，
  此时新增会给出「在回收站中，请先恢复或彻底删除」的可读提示，而不是数据库报错。
- **附件不删物理文件**：软删只写 `delete_time`（否则恢复出来是坏链），物理文件在「彻底删除」时才清理。
- **树结构防御**：部门 / 分类 / 字典数据 / 菜单在上级仍在回收站时不允许恢复，避免出现挂在已删节点下的孤儿。
- **菜单与授权**：删菜单时 `role_node` 授权**保留**（恢复后自动重新生效），但 `AuthService::permissions()`
  会把「回收站里的菜单节点」排除——否则删了菜单只是看不见入口、接口仍可调用；「彻底删除」时才真正清掉授权。
  同时 `menu-sync` / `perm-scan` 遇到「代码里仍声明、但躺在回收站」的节点会**自动恢复**，保证声明式源文件仍是权威。

### 3.9.1 数据权限模型（一期）

在 **Logic 层**由 `DataScope` 统一注入（不下沉到 Model，避免污染）。

#### 绑定维度（任一命中即生效）

| 维度 | 判定依据 |
|---|---|
| 用户 | `sys_data_rule.user_id` = 登录用户 id |
| 岗位 | 登录用户在 `sys_user_post` 中被分配了 `sys_data_rule.post_id` |
| 部门 | 登录用户在 `sys_user_dept` 中的部门 ∈ 规则 `dept_ids`（**含下级部门**） |
| 角色 | 登录用户拥有 `sys_user_role` 中的规则 `role_id` |

**合并规则**：四个维度是**「或」**——命中任意一个，这条规则就对这个人生效（多选部门之间同样是「或」）。
四个都不填 = 全局规则，对所有非超管生效。命中的多条规则按 `sort` 升序**全部叠加**（AND）。

**目标表**：`sys_data_rule.table_name` 指定规则作用于哪张表（不含前缀，如 `user`），**空 = 不限表**。
指定了表的规则只在该表上生效——否则 `status` 这类通用字段名会在所有模块上串味
（遇到没有该列的表直接 SQL 报错）。规则页的字段下拉直接读 `information_schema` 的
表注释 / 字段注释做语义化，并在保存时校验「字段确实属于该表」。

#### 角色级预置范围（`sys_role.data_scope`）

| 值 | 含义 |
|---|---|
| 1 | 全部数据 |
| 2 | 本部门及以下 |
| 3 | 本部门 |
| 4 | 仅本人 |
| 5 | 自定义（只读 `sys_data_rule`） |

#### 跨部门多选

`sys_data_rule.dept_ids` 存 **JSON 数组**（如 `[3,7,11]`），匹配时先展开成**部门子树**（含所有下级部门），
再与「登录用户所属部门」求交集。用户与部门为**多对多**（`sys_user_dept`），可同时属于多个部门，取并集。

> 展开的是**规则里绑定的部门**，不是业务表上的 `dept_id` 列。业务表的部门维度由各模块通过
> `DataScope::row($q, $user, ['dept' => ...])` 自行适配（用户表走 `sys_user_dept` 反查用户）。

#### 两类作用

**行级**：条件集合（字段 + 操作符 + 值），以 `AND` 注入查询 `where`。
操作符白名单见 `DataScope::ROW_OPERATORS`：`=` `!=` `<>` `>` `>=` `<` `<=` `like` `in` `between`。
`in` / `between` 的取值用英文逗号分隔，服务端拆成数组后走 `whereIn` / `whereBetween`。

> 安全边界：字段名正则限 `[A-Za-z_][A-Za-z0-9_]*`，且保存时校验字段确实属于目标表；
> 操作符走白名单；取值全部参数化。**per-table 字段白名单尚未实现**（管理员仍可对任意列建规则），要收紧再补。

**字段级**：四种动作，在**出参统一过滤 + 入参统一剔除**：

| 动作 | 行为 |
|---|---|
| `hidden` | 字段不出现在响应中 |
| `readonly` | 可读，提交时被强制剔除（忽略前端传值） |
| `mask` | 掩码返回，如手机号 `138****8888`、身份证 `3301**********1234` |
| `encrypt` | **密文返回**，由前端解密后展示 |

#### `encrypt` 实现与安全边界

- 算法：**AES-256-GCM**，密钥来自 `plugin.cccms.auth.data_encrypt_key`（部署时用环境变量 `DATA_ENCRYPT_KEY` 注入），**单密钥、不做版本轮换**（已确认）
- 密钥经 `sha256` 规整为 32 字节；**更换密钥后历史加密字段不可解密**，生产环境须固定并纳入密钥管理
- 下发方式：`GET /me` 返回 `crypto_key`（base64）；**未配置该键时不返回 `crypto_key`**，此时字段级 `encrypt` 会抛 500（fail-closed，不静默降级为明文）
- 前端用 `crypto-js` / `WebCrypto` 解密；服务端需要明文参与计算时用 `Cipher::decrypt()`
- **安全边界（必须知晓）**：密钥在客户端，`encrypt` 只能防「**传输抓包 / 日志留存 / 接口裸奔时看到明文**」，**不能防持登录态的用户自行解密**。它是「传输层之外的二次遮蔽」，不是访问控制手段

#### 其他规则

- 修改角色 / 数据规则本身必须走**独立权限点**，且不得授予超出自己范围的规则（防自我提权）
- 无部门、无岗位的用户 → 退化为「仅本人」
- 超管不受数据权限限制
- 数据权限变更 → `INCR` 版本号

### 3.10 附件与存储（一期）

- 一期驱动：**本地**，落盘 `server/public/storage/YYYYMMDD/`，同域访问 `/storage/...`
- **URL 不硬编码**，统一经 `FileStorage::url($path)` 生成，前缀来自 `plugin.cccms.filesystem.url_prefix`（默认 `/storage`）；列表接口按当前前缀实时生成，切 CDN 后历史数据无需迁移
- 后期切独立域名 / OSS：只改 `url_prefix` 并加驱动实现，业务代码零改动

**接口**（`FileController`）：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/file` | 分页列表（按 `original_name` / `ext` 过滤） |
| POST | `/file/upload` | 上传（`multipart`，字段名 `file`），相同内容按 `sha1` **自动去重** |
| POST | `/file/delete` | 删除（同时删除物理文件，`realpath` 校验防目录穿越） |

**上传安全约束**：

- 扩展名白名单（`filesystem.allow_ext`）+ 单文件大小上限（`filesystem.max_size`，默认 10MB）
- 落盘文件名随机化，不使用原始文件名
- **`public/storage` 必须在 Web 服务器上关闭 PHP 解析**（见 §6.1），否则构成任意代码执行风险
- **驱动可切换**：`StorageManager` 按 `filesystem.driver` 解析驱动，统一接口 `upload / url / delete`

#### 多存储驱动（二期已实现）

| 驱动 | `driver` 值 | 依赖（按需安装，未装时给出明确提示） |
|---|---|---|
| 本地 | `local` | 无 |
| 阿里云 OSS | `oss` | `composer require aliyuncs/oss-sdk-php` |
| 腾讯云 COS | `cos` | `composer require qcloud/cos-sdk-v5` |
| 七牛云 Kodo | `qiniu` | `composer require qiniu/php-sdk` |

配置位于 `plugin/cccms/config/filesystem.php`，支持 `STORAGE_DRIVER` / `OSS_*` / `COS_*` / `QINIU_*` 环境变量覆盖。

> ⚠️ `url()` 由**当前**驱动生成，切换存储后端后需迁移历史文件；`sys_file.driver` 记录了每个文件的上传驱动，便于排查。

### 3.11 扫描校验命令

```bash
php webman cccms:perm-scan            # 扫描属性 → 校验 + 同步 sys_menu 按钮节点
php webman cccms:perm-scan --check    # 只校验不写库（CI 用）
php webman cccms:menu-sync            # 同步 db/menu.php 的目录/菜单节点
php webman cccms:db-upgrade           # 已有库补新增的表/列/索引 + 统一时间戳列（幂等，可重复执行）
```

> **前两条也可以在后台点**：顶栏「系统同步 / 清理缓存」按钮（`POST /system/refresh`，权限点 `cccms:config:refresh`）
> 提供 `menu`（= `menu-sync`）/ `perm`（= `perm-scan`）/ `cache`（清 `sys_config` 缓存 + 注解元数据）/ `all` 四种范围，
> 与 CLI **共用同一份实现**（`MenuSyncer` / `PermScanner`），结果一致。
> 同步完会自动重挂前端动态路由并刷新当前用户权限，侧边栏立即生效，不用再手动刷新页面。
> 唯一需要 CLI 的是首次 `db-upgrade`（改表结构不适合放界面）以及 `perm-scan --check`（CI 校验）。

> `schema.sql` 用的是 `CREATE TABLE IF NOT EXISTS`，对**已存在**的表不会补列，
> 所以升级存量库必须走 `cccms:db-upgrade`；新装环境直接导入 `schema.sql` 即可，不需要执行它。
> 该命令同时把 `create_time` / `update_time` 统一改成数据库默认值（见 6.2「时间戳」）。

校验项：

1. slug 格式合法（§3.1 正则），**全局唯一**
2. 每个 slug 都能（经最长前缀或 `group`）找到归属菜单
3. `#[Permission]` / `#[NoAuth]` / `#[NoLogin]` 互斥校验
4. 类级 `#[NoLogin]` 内不得出现 `#[Permission]`
5. `#[Restrict]` 参数取值合法（`methods` 属于 HTTP 方法表，`encode` 属于 `json/jsonp/xml/view`）
6. `BaseController` 子类的 public 方法**必须**有属性（无属性即 fail-closed，扫描直接报错）
7. 路由表中每条业务路由的 handler 必须是 `[Controller::class, 'method']` 数组（禁止业务闭包路由）
8. **声明了 `#[Permission]` 但无任何路由引用** → 报错（僵尸声明的另一面）
9. 反向列出 `sys_menu` 中存在、但代码已无对应注解的僵尸节点（**只报告不自动删**，避免误删已授权数据）
10. `sys_role.parent_id` 环检测 + 深度上限校验

### 3.12 请求生命周期

```
请求
 └─ Cors                 跨域
 └─ CheckLogin           解析 token → $request->user（UserContext）；#[NoLogin] 跳过
 └─ CheckAuth            ① 认证 401 → ② 授权 403 → ③ 方法 405
 └─ ResponseEncode       ④ 编码白名单 406，确定本次响应编码
 └─ Controller
     └─ Logic            业务逻辑 + 数据权限（行级 where / 字段级出入参过滤）
         └─ Model        数据访问
 └─ OperationLog         操作日志（写操作记录）
 └─ Result               按编码渲染 json / jsonp / xml / view
```

---

## 四、功能需求清单

### 一期（P0）

| 模块 | 内容 |
|---|---|
| 认证 | 账号密码登录、登出、图形验证码、JWT 签发/校验、登录失败限流 |
| RBAC | 用户、角色（含继承）、菜单（节点）管理；用户↔角色、角色↔节点 |
| 组织 | 部门（无限级，用户可多部门）、岗位；用户↔部门、部门↔角色、用户↔岗位 |
| 数据权限 | 角色的 `data_scope`（5 档）+ `sys_data_rule`（行级 / 字段级，多维绑定）；**当前已接入：用户管理** |
| 鉴权 | 4 个属性 + 中间件 + `perm-scan` 校验命令 |
| 响应 | 统一出口 `Result`，支持 json / jsonp / xml / view |
| 菜单 | `db/menu.php` 声明目录/菜单，按钮由注解生成；菜单管理界面 |
| 系统维护 | 顶栏「同步 / 清理缓存」：等价于 `menu-sync` + `perm-scan` + 清缓存，与 CLI 共用实现 |
| 个人中心 | 静态路由 `/profile`（不进左侧菜单），入口在右上角用户下拉；改资料 + 改密码，字段白名单限定为本人可改项 |
| 回收站 | 业务主数据（含菜单）软删除；入口是各模块页面表格右上角的图标，点一下**同一张表换成查已删数据**（列表接口 `trashed=1`），操作列变「还原 / 彻底删除」（`useRecycle` + `ArtTable.recycle`） |
| 系统配置 | 键值配置 + 可视化表单（switch / select / input-number / textarea） |
| 数据字典 | 类型 + 数据两级结构 |
| 操作日志 | 记录请求参数、执行结果、耗时、IP、UA |
| 附件管理 | 本地驱动 `public/storage`，URL 统一由 `url_prefix` 生成 |
| 基础防护 | 全局 XSS 过滤、CORS、统一异常响应 |

#### 数据权限语义（已接入：用户管理）

`sys_role.data_scope` 决定「基线」，`sys_data_rule` 里的**行级规则**在其上**叠加（AND）**：

| data_scope | 行级基线 | 自定义行级规则 |
|---|---|---|
| 1 全部数据 | 无 | **不生效**（「全部」= 不受约束） |
| 2 本部门及以下 | 我的部门 + 所有下级部门 | 叠加 |
| 3 本部门 | 我的部门（不含下级） | 叠加 |
| 4 仅本人 | 用户表 = 只能看自己这个账号；业务表 = `create_by = 我` | 叠加 |
| 5 自定义规则 | 无 | 只应用规则；**一条都没命中 = 看不到任何数据**（fail-closed） |

- 多角色取**最宽松**（`min`）；无角色退化为「仅本人」
- **字段级规则与 `data_scope` 无关**，只要绑定匹配就生效（超管除外）：`hidden` / `mask` / `encrypt` 作用于出参，`readonly` 作用于入参（强制剔除，防越权提交）
- 绑定维度 `user_id` / `post_id` / `role_id` / `dept_ids` **任一命中即生效（OR）**；四个都空 = 全局规则
- 绑定**部门含下级**：绑「总公司」覆盖其所有下级部门的人（树选择器的直觉）
- **目标表**：规则可指定作用于哪张表（`table_name`，空 = 不限表）；指定了表的规则只在该表生效
- **动态取值**（`value_type = dynamic`）：行级取值的 `{变量}` 在执行时按**当前用户**解析，可与字面量混用（逗号分隔，合并成集合走 `IN`）：
  `{user.id}` 当前用户 / `{dept.ids}` 我的部门 / `{dept.subtree}` **我的部门及所有下级** / `{post.ids}` 我的岗位 / `{role.ids}` 我的角色。
  于是「本部门及其子部门 **且** 自定义条件」可以统一写成 `dept_id in {dept.subtree}` 这样的普通规则；
  变量解析为空（如用户没有部门）时该条规则不生效，不会退化成不过滤
- **受控表**：`sys_data_scope_table` 维护「哪些表可以配数据权限」（规则页右上角「受控表」可视化增删/停用），
  规则页的「目标表」只列出受控表（字段随表**级联**选择），保存时校验、运行期也只用受控表的规则；
  未登记的表一律隐藏，避免配出「永远不会生效」的规则。兜底名单 `DataScope::DEFAULT_TABLES` 仅在该表不存在/为空时生效
- **自定义档 fail-closed**：`data_scope=5` 且没有任何规则命中时看不到任何数据——避免「绑错对象 / 忘了配规则」静默变成「看全部」
- 系统表没有 `create_by` / `dept_id`，由各模块用 `DataScope::row($q, $user, $options)` 说明维度：
  用户管理传 `owner => 'id'`（仅本人 = 只看自己）与 `dept`（经 `sys_user_dept` 反查用户）
- **fail-closed**：声明了「本部门」却没有任何部门时返回空结果，而不是退化成「不过滤」
- 只过滤列表不够：`read` / `update` / `delete` / `resetPassword` 会再校验一次，越权返回 403

### 二期（P1）

| 模块 | 内容 | 状态 |
|---|---|---|
| 定时任务 | Cron 表达式（6 段含秒 + 可视化编辑）、每秒调度进程、执行白名单、执行日志、立即执行 | ✅ |
| 代码生成 | 按库表生成 model / logic / controller / 路由 / API / 页面，并登记菜单 | ✅ |
| 多存储 | `StorageManager` 驱动抽象 + local / OSS / COS / 七牛 | ✅ |
| 前台应用 | 独立 `plugin/index` 插件（公开路由，与后台中间件隔离） | ✅ |

#### 定时任务设计要点

- **调度方式**：`plugin/cccms/process/Crontab` **每秒 tick 一次**（秒级表达式需要秒级精度），读取 `sys_crontab` 中启用任务判断是否到期 → **增删改任务无需 reload 进程**；任务在调度进程内串行执行，长任务会顺延后续 tick
- **表达式**：`CronMatcher` 自实现匹配，**六段（秒 分 时 日 月 周）**；每段支持星号 / 单值 / 列表 / 区间 / 步长，日与周同时限定时按 AND 处理。**只认 6 段**（不兼容 Linux 的 5 段写法）
- **按秒去重**：调度进程每秒 tick，`markAndCheckHit` 按秒去重，保证同一秒只跑一次
- **可视化编辑**：前端 `CronEditor` 用**宝塔面板式**的引导交互 —— 只问两个问题：「执行周期」（每秒 / 每 N 秒 / 每 N 分钟 / 每 N 小时 / 每天 / 每周 / 每月）+「具体时间」（几点几分 / 星期几 / 几号），不要求懂表达式；需要复杂规则（如每周一三五）时可切「自定义表达式」直接写六段。底部固定回显生成的表达式、中文说明与**最近 3 次运行时间**（周期与表达式的互转、运行预览见 `utils/cron.ts`，与后端 `CronMatcher` 同语义）
- **执行白名单**：只执行**在代码中实现 `CrontabTask` 接口**的类；数据库里存的目标类名无法指向任意方法（杜绝 RCE）
- **执行器**：`CrontabRunner`（调度进程与「立即执行」共用），写 `sys_crontab_log` 并更新 `last_run_time` / `next_run_time`
- **平台限制**：自定义进程仅在 Linux / macOS 生效，Windows 下可用前端「立即执行」验证
- **新增任务**：在 `plugin/cccms/command/task/` 下新建实现 `CrontabTask` 的类，会自动出现在前端「执行目标」下拉中

#### 代码生成器设计要点

- **产物 6 个文件**：Model / Logic / Controller / 模块路由 / 前端 API / 前端页面，并自动在 `sys_menu` 登记菜单（type=2）
- **沿用统一命名**：生成 `cccms:product:*` 注解与 `pages/cccms/product/index.vue`，因此**生成即符合 fail-closed**，不会出现「生成完就 500」
- **路由落位**：写入 `plugin/{插件}/config/route/{模块}.php`，由 `config/route.php` 末尾统一 `require`，避免改写主路由文件
- **不覆盖策略**：目标文件已存在时默认报错，勾选「允许覆盖」才覆盖
- **生成后**：执行 `php webman cccms:perm-scan` 同步按钮节点；若要让菜单进入声明式源文件，把界面给出的 snippet 粘到 `db/menu.php`

#### 前台应用设计要点

- **独立插件**：`server/plugin/index`，路由前缀 `/site/*`，与后台 `cccms` 插件完全隔离
- **中间件按插件作用域**：`Middleware::getMiddleware($plugin, …)` 只取 `instances[$plugin]['']`，所以 `cccms` 的 `CheckLogin` / `CheckAuth` **不会**作用于本插件 —— 前台天然公开，无需在每个方法上写（写了也不会执行的）`#[NoLogin]`
- **复用响应**：仍继承 `plugin\cccms\basic\BaseController`，保持统一 `{code,message,data}` 信封
- **已实现示例**：`GET /site/ping`、`GET /site/home`

---

## 五、核心库表

| 表 | 说明 |
|---|---|
| `sys_user` | 用户 |
| `sys_role` | 角色（`code` 唯一、`data_scope`、`parent_id` 继承） |
| `sys_user_role` | 用户↔角色（直连） |
| `sys_menu` | 菜单 + 权限节点树（`type` 1目录/2菜单/3按钮，`node` = slug，唯一索引） |
| `sys_role_node` | 角色↔节点授权（`node` = slug） |
| `sys_dept` | 部门（无限级） |
| `sys_user_dept` | 用户↔部门（**多对多**） |
| `sys_dept_role` | 部门↔角色 |
| `sys_post` | 岗位 |
| `sys_user_post` | 用户↔岗位 |
| `sys_data_rule` | 数据权限规则（行级 + 字段级；`dept_ids` 为 JSON 数组，`table_name` 指定目标表，`value_type` 区分静态/动态取值） |
| `sys_data_scope_table` | 数据权限**受控表**（哪些表可以配数据权限；规则页「受控表」可视化维护） |
| `sys_dict_type` / `sys_dict_data` | 数据字典（类型可归属分类 `category_id`） |
| `sys_category` | **通用分类**（`module` 区分 `dict` / `file`，可层级；字典分类与附件分类共用一张表） |
| `sys_config` | 系统配置（键值 + 控件类型 + 分组；种子内置 5 组 24 项） |
| `sys_log` | 操作日志（`node` / `title` 为语义化标识，`params` / `result` 存请求与响应原文） |
| `sys_crontab` / `sys_crontab_log` | 定时任务（二期） |
| `sys_file` | 附件（`category_id` 归属分类，0 = 未分类） |

### 配置项 → 生效点（`sys_config`）

种子内置 5 组 24 项，**全部已接入实际逻辑**（保存后立即生效，无需重启）：

| 配置键 | 生效位置 | 行为 |
|---|---|---|
| `system.name` | `GET /config/ui` → 前端 | 浏览器标题、侧边栏品牌、登录页品牌 |
| `system.logo` | 同上 | 侧边栏 / 登录页 Logo；留空回退内置 `frontend/public/logo.png`（该文件同时用于首屏 loading 与站点图标 `<link rel="icon">`） |
| `system.icp` / `system.copyright` | 同上 | 侧边栏底部与登录页页脚 |
| `system.maintenance` | `Maintenance` 中间件 + `SessionGuard` + `AuthLogic::login` | 开启后**非超管**：已登录会话**立即失效**（401 + 公告 → 前端清 token 回登录页）、接口 503、且无法重新登录；**超管不受影响** |
| `system.maintenance_notice` | 同上 | 503 消息内容与登录页维护提示 |
| `ui.theme_mode` / `ui.theme_primary` / `ui.container_width` | `GET /config/ui` → 前端 | 新用户（本机无偏好）的默认主题；主题面板可「跟随系统默认」清回 |
| `ui.page_size` | `useTable` | 列表页默认每页条数 |
| `ui.tags_view` | `BasicLayout` | 是否显示多标签页 |

> **维护模式如何做到「即时退出」**：`TokenService` 是无状态 JWT，服务端不存会话，所以用一个 Redis 分界线
> `cccms:session:min_iat`（`SessionGuard`）——开启维护时把它推到当前时间，`CheckLogin` 里凡是
> **签发时间 ≤ 分界线**且**非超管**的令牌一律 401；前端走既有 401 逻辑（清 token → 提示 → 回登录页），
> 登录页会展示维护公告。分界线 TTL 取令牌有效期，保证活到受影响的令牌自然过期；**超管豁免**，否则维护期间没人能进系统。
> 触发点是「用户的下一次请求」，所以闲置页面不会有视觉变化。
| `security.login_captcha` | `AuthController::captcha` + `AuthLogic::login` | 开启后登录必须带图形验证码（SVG 生成、不依赖 GD，答案存 Redis 且一次性） |
| `security.login_fail_limit` / `login_fail_window` | `LoginThrottle` | 连续失败达阈值后锁定窗口时长（返回 429）；`0` 表示关闭该机制 |
| `security.token_ttl` | `TokenService::ttl` | JWT 有效期（秒） |
| `security.password_min_length` | `UserLogic` | 新增用户 / 重置密码的最小长度校验 |
| `upload.max_size` | `StorageDriver::validate` | 单文件大小上限（MB） |
| `upload.ext_allow` | 同上 | 扩展名白名单（留空回退 `filesystem.php`） |
| `upload.image_ext` | `FileLogic` | 附件列表 `is_image` 标记 → 前端缩略图 |
| `upload.storage_driver` | `StorageManager::current` | 存储驱动（local / oss / cos / qiniu） |
| `upload.url_prefix` | `LocalDriver::url` | 本地附件访问前缀（切独立域名 / CDN 改这里） |
| `log.record_read` | `OperationLog` | 开启后 GET 请求也写日志（**日志量会明显增加**） |
| `log.keep_days` | `DemoTask`（定时任务） | 清理多少天前的日志；`<=0` 跳过 |
| `log.auto_clean` | 同上 | 关闭后该任务直接跳过（含「立即执行」） |

> **配置读取**：`plugin/cccms/support/SysConfig.php`，取数顺序 Redis（TTL 300s）→ MySQL，保存后 `flush()` 立即失效。
> 这里**刻意不做进程内缓存**：webman worker 是长驻进程，静态属性跨请求存活，一旦缓存住就会出现「后台改了配置却不生效」，必须等 TTL 过期或重启。
> **新增配置项**：`db/seed.sql` 加一行 + 消费处调用 `SysConfig::getInt/getBool/getString/getList('your.key', 默认值)`。

---

## 六、关键实现约束（安全与一致性）

### 6.1 安全硬约束（必须实现，不可省略）

| # | 约束 | 原因 |
|---|---|---|
| 1 | **数据权限的字段名与操作符必须白名单** | `sys_data_rule` 的条件字段会进 SQL `where`。当前实现：字段名正则限 `[A-Za-z_][A-Za-z0-9_]*`、保存时校验字段属于目标表、操作符限 `DataScope::ROW_OPERATORS`、取值一律参数化——注入面已封死；另由「**受控表**」限定只有登记过的表才能建规则、目标表候选也只列受控表的字段。**per-table 字段白名单（`dataFields()`，只放行表内部分列）尚未实现**：受控表内的任意列仍可建规则（属管理端能力，要收紧再补） |
| 2 | **`public/storage` 禁止解析 PHP** | 同域上传目录若可执行脚本 = 任意代码执行。Nginx 对该目录关闭脚本解析；上传做扩展名 + MIME 双白名单、随机重命名、大小上限 |
| 3 | **Token 必须与用户状态强关联** | 只验签名会出现「禁用/删除用户后旧 token 仍可用」。当前实现：`CheckLogin` 每次请求都从 DB 重建用户上下文，所以**禁用 / 删除用户立即 401**；另由 `SessionGuard` 提供全局「签发时间分界线」（维护模式踢人即用它）。**已知缺口：改密码不会让旧令牌失效**，要补可基于 `SessionGuard` 加一个按用户的封禁基准 |
| 4 | **`#[NoAuth]` 不下放给下拉/选项接口** | 登录即可 = 任意登录用户拿到全量数据。下拉数据走 `#[Permission]` 或叠加数据权限 |
| 5 | **角色继承必须防环 + 限深** | `parent_id` 成环会让权限解析死循环；保存时强制环检测，深度上限 5 |
| 6 | **登录失败限流 + 密码策略** | Redis 计数，连续 5 次失败锁 10 分钟；初始密码强制修改 |

### 6.2 一致性与可维护性

| # | 约束 | 原因 |
|---|---|---|
| 7 | `perm-scan --sync` **只增不删**（已存在的仅恢复软删 + 纠正归属菜单） | 不覆盖 `title` / `sort`，避免每次部署冲掉后台界面的人工调整；但父菜单变了要跟着改 `parent_id`，否则按钮挂在已删除节点下，在授权树里看不见 |
| 8 | **业务主数据软删除**（回收站），关联表 / 日志 / 配置不软删 | 主数据（含菜单）误删可恢复；关联表随主表生命周期，软删只会制造脏数据；日志用保留天数清理。详见 §3.9 |
| 8.1 | 唯一键**不做软删特例** | `user.username` / `role.code` / `post.code` / `dict_type.type` 软删后仍占用该值，新增时给可读提示而非数据库报错；要复用请在回收站里彻底删除 |
| 9 | JSON 字段用 ORM 的 JSON 类型 | `dept_ids`、角色节点等禁止逗号拼接字符串 |
| 10 | 时间 / 金额序列化统一 | 时间统一 `Y-m-d H:i:s` 字符串；金额用 `decimal` 字符串，禁止 float |
| 11 | 唯一索引 | `sys_menu.node`、`sys_role.code` |
| 12 | 路由与注解双向校验 | 「有注解无路由」「有路由无注解」都报错（见 §3.11 第 6、8 项） |
| 13 | 模型表名用 `protected $name = 'menu'`（**不含** `sys_` 前缀） | think-orm 里 `$name` 才是「不含前后缀」的表名，由连接配置 `prefix` 自动补全；`$table` 是**完整表名、原样使用**，写 `$table = 'menu'` 会直接查不存在的 `menu` 表。同理逻辑层统一 `Db::name('menu')` |
| 14 | **时间戳交给数据库默认值**（`DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP`） | 业务层统一走 `Db::name()` 查询构造器，**不吃模型的 `autoWriteTimestamp`**（`config/database.php` 的 `auto_timestamp` 只对模型生效）。用列默认值兜底后，任何写入路径都不会漏掉 `create_time` / `update_time`，不必在每处手动 `date()` |
| 15 | 回收站的恢复 / 彻底删除是**独立权限点** | `cccms:recycle:restore` / `cccms:recycle:delete` 能跨模块恢复或不可逆地摧毁数据，属于高危能力，不要与其他模块权限混授。回收站**视图本身不设权限点**：它就是各模块列表的 `trashed` 开关，能不能看那份列表本来就受 `xxx:index` 控制 |

### 6.3 建议默认（无异议即按此执行）

| 项 | 默认 |
|---|---|
| 多端登录 | 允许，互不影响（不挤下线） |
| 角色继承深度 | ≤ 5 |
| 令牌有效期 | accessToken 7 天，支持 refresh |
| 字段级 `encrypt` 密钥 | 单密钥，不做版本轮换（已确认） |
| 前端页面注册 | `frontend/src/pages/**`，路由由菜单 `component` 字段动态映射 |

---

## 七、确认状态

### 已确认

| # | 事项 | 结论 |
|---|---|---|
| 1 | 后端框架 / ORM | Webman 2.x + think-orm ✅ |
| 2 | 前端技术栈 | Vue 3 + Vite + **TypeScript** + **Element Plus** + **Tailwind CSS 4** ✅<br>（原定 Arco；后按 `docs/saiadmin6.x/saiadmin-artd`（art-design-pro）的设计体系改为 Element Plus 并整体重做 UI） |
| 3 | `#[Restrict]` | 保留属性形式；**同时管 `methods` 与 `encode`** ✅ |
| 4 | 超管判定 | `sys_role.code = 'super_admin'`，非 `id === 1` ✅ |
| 5 | 一期范围 | 认证 / RBAC / 部门 + 岗位 + 数据权限 / 鉴权 / 菜单 / 配置 / 字典 / 日志 / 附件(本地) ✅ |
| 6 | 前台应用 | 本期**只做管理后台**，不做 index 前台 ✅ |
| 7 | 字段级权限动作 | `hidden` / `readonly` / `mask` / **`encrypt`** ✅ |
| 8 | 数据范围 | **支持跨部门多选**（`dept_ids` JSON 数组 → `IN`）✅ |
| 9 | 附件存储 | 本地 `public/storage` 同域；URL 经 `url_prefix` 生成，后期可切独立域名/OSS ✅ |
| 10 | 响应体系 | **保留 `json` / `jsonp` / `xml` / `view`** ✅ |
| 11 | 角色继承 | **方案 A：子角色继承父角色**（父 = 基线，子 = 基线 + 增量）✅ |
| 12 | `encrypt` 密钥轮换 | **不需要**，单密钥 ✅ |

**需求已全部确认，无遗留待定项。**

---

## 八、快速开始

### 后端

```bash
cd server
composer install

# 1) 导入数据库（自行建库）
#    CREATE DATABASE cccms DEFAULT CHARSET utf8mb4;
#    导入 plugin/cccms/db/schema.sql
#    导入 plugin/cccms/db/seed.sql

# 2) 同步菜单与按钮节点
php webman cccms:menu-sync        # 目录/菜单（读 db/menu.php）
php webman cccms:perm-scan        # 按钮（扫描控制器注解）；加 --check 只校验不写库
php webman cccms:db-upgrade       # 已有库补新增表/列/索引（新装环境不需要）

# 3) 启动（默认 http://0.0.0.0:8787）
php windows.php           # Windows
php start.php start       # Linux / macOS
```

- 数据库连接：`plugin/cccms/config/database.php`，支持 `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_PORT` 环境变量覆盖
- JWT 密钥：`plugin/cccms/config/auth.php` 的 `secret`，**生产环境务必替换**（HS256 要求 ≥ 32 字节）
- 字段级 `encrypt` 密钥：同文件的 `data_encrypt_key`

### 前端

```bash
cd frontend
npm install
npm run dev      # http://localhost:5173
npm run build    # 产物 dist/
```

开发期 `/api` 由 Vite 代理到 `http://127.0.0.1:8787`（见 `frontend/vite.config.ts`），因此前端统一以 `/api` 前缀调用接口。

### 默认账号

`admin` / `admin123`（首次登录后请立即修改）

### 新增一个业务接口

```php
#[Permission(slug: 'cccms:user:save', title: '用户保存')]
#[Restrict(methods: ['POST'])]
public function save(Request $request): Response { /* ... */ }
```

改完执行 `php webman cccms:perm-scan`，按钮节点会自动挂到最长前缀匹配的菜单下。
前端按钮用 `v-auth="'cccms:user:save'"` 控制显隐。

### 前端页面约定（新增页面照这个来）

**1. 组件名必须等于菜单 slug** —— keep-alive 的精确缓存依赖它：

```ts
defineOptions({ name: 'cccms:user' })   // 必须 = sys_menu.node
```

所有页面文件都叫 `index.vue`，不显式声明 `name` 的话，推断出的组件名会互相撞车，`<keep-alive :include>` 就匹配不上（表现：关闭标签页后组件仍被缓存，内存不释放）。

**2. 列表页统一用 `ArtTable` + `useTable`**：

```vue
<div class="art-fill">
  <ArtTable
    :columns="columns" :data="list" :loading="loading" :total="total"
    v-model:page="page" v-model:limit="limit"
    @refresh="load" @search="search" @reset="reset"
    @page-change="onPageChange" @size-change="onLimitChange"
  >
    <template #search>…查询条件…</template>
    <template #toolbar><el-button v-auth="'cccms:user:save'">新增</el-button></template>
    <template #status="{ row }">…</template>
    <template #action="{ row }">…</template>
  </ArtTable>
</div>
```

| 约定 | 说明 |
|---|---|
| `.art-fill` | 撑满内容区、表格主体内部滚动（**列表页必须**） |
| `.art-scroll` | 页面整体纵向滚动（配置页、生成器页这类表单页用） |
| 树形表格 | 部门 / 菜单：`:pagination="false"` + `tree` |
| 列显隐 | `columns` 里配 `slot` / `lockVisible`；用户勾选状态按路由存 localStorage |

**3. 图标用字符串名**：`<el-icon><ArtIcon name="icon-user" /></el-icon>`；注册表在 `utils/icon.ts`，菜单图标选择器的候选来自 `MENU_ICON_OPTIONS`。

**4. 颜色只用设计 token**：写 `var(--art-*)`，不要在组件里写死颜色，否则暗色模式会花。令牌见 `assets/styles/tokens.css`。

**5. 权限按钮用 `v-auth`**：`v-auth="'cccms:user:save'"`，支持数组（命中任一即显示）。

**6. 不要在兜底路由上标 `meta.public`** —— 那会让「刷新页面」误判成 404：

刷新时首帧一定先匹配到 `/:pathMatch(.*)*`（此时动态路由尚未注册），而路由守卫**必须先注册动态路由、再判断 404**。如果兜底路由带了 `meta.public`，守卫会在注册之前就提前返回，于是任何菜单页一刷新就显示 404（点击进入却正常）。

---

## 状态

### 一期（P0）—— 已完成

- [x] 需求确认（1~12 全部确认）
- [x] `server/`：Webman + 插件骨架（think-orm / redis / jwt / console）
- [x] 注解 4 件套 + `PermissionMeta` + `CheckLogin` / `CheckAuth` / `ResponseEncode`
- [x] `Result`（json / jsonp / xml / view）+ 统一异常处理 + JWT
- [x] 数据库 15 表 + think-orm 配置 + 种子
- [x] `perm-scan` / `menu-sync` 命令 + `db/menu.php`（`--check` 扫描 45 方法通过）
- [x] RBAC（含角色继承）+ 组织（部门/岗位）
- [x] 数据权限：`DataScope` 行级 + 字段级规则、`sys_data_rule` 管理页面与接口；**已接入用户管理**（列表过滤 + 单条越权防护 + 部门维度走 `sys_user_dept`），其余模块待铺开
- [x] 附件管理（本地 `FileStorage`：上传 / 列表 / 删除 + sha1 去重 + 类型与大小白名单）+ **分类树 / 移动到分类**
- [x] 操作日志落库（`OperationLog` 中间件写 `sys_log`，**语义化 `node` / `title` + 请求参数 + 返回结果 + IP / UA**，敏感字段递归脱敏，写失败不影响业务）
- [x] 基础防护（CORS + 全局 XSS 过滤中间件 + 统一异常响应）
- [x] `frontend/`：登录、动态路由、`v-auth`、11 个模块页面（`vue-tsc` 与 `vite build` 通过）
- [x] 前端 UI 整体重做（Element Plus + Tailwind 4）：
  - 布局骨架：侧边栏（递归菜单 + 折叠 + 移动端抽屉）、顶栏、**多标签页**（右键菜单 / 固定 / 批量关闭 / 刷新）、面包屑、keep-alive 精确缓存、404
  - 主题体系：亮色 / 暗色 / 跟随系统 + 主色 / 圆角 / 内容区宽度可调，偏好持久化
  - 列表页体系：`ArtTable` + `useTable`（搜索栏 / 工具栏 / 列设置 / 高度自适应 / 分页），11 个页面全部改造
  - 登录页重做（品牌区 + 表单区，验证码接口就绪后自动出现）+ 工作台仪表盘（统计卡 + ECharts 折线/饼图）
- [x] 工作台统计接口 `GET /dashboard/stats`（`#[NoAuth]`：只返回聚合计数，不含业务明细）

### 二期（P1）—— 已完成

- [x] 定时任务（`CrontabTask` 白名单 + 调度进程 + 执行日志 + 立即执行 + 前端页面）
- [x] 代码生成器（6 类产物 + 菜单登记 + 预览 / 覆盖控制 + 前端页面）
- [x] 多存储驱动（`StorageManager` + local / OSS / COS / 七牛，SDK 按需安装）
- [x] 前台应用（`plugin/index` 公开路由插件，与后台中间件隔离）
