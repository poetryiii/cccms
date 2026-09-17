# CCCMS

> 企业级后台管理系统 / 快速开发框架 —— 插件化、注解驱动、fail-closed 的后台底座。

CCCMS 是一套基于 **Webman 2.x（PHP 常驻内存框架）+ Vue 3** 的中后台管理系统，适合作为中后台项目的起点或二次开发底座。后端采用 Webman 插件化架构 + think-orm + JWT 鉴权，前端采用 Vue 3 + Vite + TypeScript + Element Plus + Tailwind CSS 4，并内置角色权限（RBAC）、数据权限、菜单与按钮权限、软删除回收站、代码生成器、定时任务、多存储驱动等能力。

---

## 一、技术栈

| 层 | 技术 |
|----|------|
| 后端 `server/` | PHP >= 8.1、Webman 2.x、think-orm、MySQL 8、Redis |
| 前端 `frontend/` | Vue 3 + Vite + TypeScript + Element Plus + Tailwind CSS 4 + Pinia + Axios + ECharts |
| 鉴权 | JWT（accessToken），Redis 存会话/黑名单 |
| 后端形态 | Webman 插件化（`plugin/*`） |
| 响应格式 | 统一出口 `Result`，支持 **json / jsonp / xml / view** 四种编码，默认 `json` |
| 部署 | 原生启动 / Docker（`Dockerfile` + `docker-compose.yml`） |

---

## 二、功能特性

### 一期（P0）—— 已完成

| 模块 | 内容 |
|------|------|
| 认证 | 账号密码登录、登出、图形验证码、JWT 签发/校验、登录失败限流 |
| RBAC | 用户、角色（含继承）、菜单（节点）管理；用户↔角色、角色↔节点 |
| 组织 | 部门（无限级，用户可多部门）、岗位；用户↔部门、部门↔角色、用户↔岗位 |
| 数据权限 | 角色 `data_scope`（5 档）+ `sys_data_rule`（行级 / 字段级，多维绑定）；**模型层自动生效**，已接入 user / dept / file / crontab / log |
| 鉴权 | 4 个属性 + 中间件 + `perm-scan` 校验命令 |
| 响应 | 统一出口 `Result`，支持 json / jsonp / xml / view |
| 菜单 | `db/menu.php` 声明目录/菜单，按钮由注解生成；菜单管理界面 |
| 系统维护 | 顶栏「同步 / 清理缓存」：等价于 `menu-sync` + `perm-scan` + 清缓存，与 CLI 共用实现 |
| 个人中心 | 静态路由 `/profile`（不进左侧菜单），入口在右上角用户下拉 |
| 回收站 | 业务主数据软删除；入口是各模块页面表格右上角图标，同一张表切换查已删数据 |
| 系统配置 | 键值配置 + 可视化表单（switch / select / input-number / textarea） |
| 数据字典 | 类型 + 数据两级结构 |
| 操作日志 | 记录请求参数、执行结果、耗时、IP、UA，敏感字段递归脱敏 |
| 附件管理 | 本地驱动 `public/storage`，URL 统一由 `url_prefix` 生成 |
| 基础防护 | 全局 XSS 过滤、CORS、统一异常响应 |

### 二期（P1）—— 已完成

| 模块 | 内容 |
|------|------|
| 定时任务 | Cron 表达式（6 段含秒 + 可视化编辑）、每秒调度进程、执行白名单、执行日志、立即执行 |
| 代码生成 | 按库表生成 model / logic / controller / 路由 / API / 页面，并登记菜单 |
| 多存储 | `StorageManager` 驱动抽象 + local / OSS / COS / 七牛 |
| 前台应用 | 独立 `plugin/index` 插件（公开路由，与后台中间件隔离） |

---

## 三、环境要求

- PHP >= 8.1（推荐 8.3，Docker 镜像使用 8.3）
- 扩展：`pdo`、`pdo_mysql`、`pcntl`、`redis`；建议 `event`（性能）
- MySQL >= 8.0、Redis >= 5.0
- Node.js >= 18（前端构建）

---

## 四、目录结构

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
│   │   │   │   ├── controller/      # 18 个控制器 Auth/User/Role/Menu/Dept/Post/Dict/Config/Log/File/Crontab/Generator/DataRule/Recycle/Profile/Maintenance/Dashboard/DataScopeTable
│   │   │   │   ├── middleware/      # Cors / Xss / CheckLogin / CheckAuth / ResponseEncode / OperationLog / Maintenance
│   │   │   │   ├── model/           # 21 个 think-orm 模型（极薄）
│   │   │   │   └── logic/           # 19 个 Logic（业务逻辑层，DB 操作收口）
│   │   │   ├── basic/               # BaseController（ok/fail 响应出口）
│   │   │   ├── support/             # 框架核心（34 个类）
│   │   │   │   ├── attribute/       # Permission / NoAuth / NoLogin / Restrict（4 个注解）
│   │   │   │   ├── storage/         # StorageDriver + Local / Oss / Cos / Qiniu / StorageManager
│   │   │   │   ├── Result / ApiException / ExceptionHandler
│   │   │   │   ├── PermissionMeta / AuthService / TokenService / UserContext / SessionGuard
│   │   │   │   ├── DataScope / Cipher / SoftDelete / SysConfig
│   │   │   │   ├── CrontabTask / CrontabRunner / CronMatcher
│   │   │   │   └── PermScanner / MenuSyncer / Captcha / LoginThrottle
│   │   │   ├── command/             # perm-scan / menu-sync / db-upgrade；task/ 下为定时任务类
│   │   │   ├── config/              # app / middleware / route / exception / response / auth / filesystem / database / bootstrap / process
│   │   │   ├── db/                  # schema.sql / seed.sql / menu.php
│   │   │   └── process/             # Crontab 调度进程
│   │   └── index/                   # 前台应用插件（公开路由 /site/*）
│   ├── public/
│   │   └── storage/                 # 本地附件目录（同域访问 /storage/...，禁止解析 PHP）
│   ├── composer.json
│   ├── Dockerfile / docker-compose.yml
│   └── webman / start.php / windows.php
│
├── frontend/                        # 前端（Vue 3 + Vite + TS + Element Plus）
│   ├── src/
│   │   ├── api/                     # 接口封装（request.ts 统一出口：鉴权头/错误提示/401 跳转）
│   │   ├── assets/styles/           # tokens.css（设计 token）/ element.css / app.css / index.css
│   │   ├── components/core/         # ArtTable / ArtSplitView / ArtTreePanel / ArtIcon / ArtSettingsDrawer / RecycleToggle
│   │   ├── composables/             # useTable / useRecycle 等
│   │   ├── directives/index.ts      # v-auth 按钮级权限指令
│   │   ├── layouts/                 # BasicLayout + 侧边栏/顶栏/标签栏/内容区
│   │   ├── pages/                   # 页面（按插件分目录，与 server/plugin 对应）
│   │   │   └── cccms/               # 登录/工作台/个人中心/各模块页面
│   │   ├── router/index.ts          # 动态路由（菜单驱动）+ NProgress
│   │   ├── stores/                  # user / menu / app / worktab
│   │   ├── types/table.ts            # 列表页列配置类型
│   │   └── utils/                   # auth / crypto / icon / theme / cron
│   ├── vite.config.ts
│   └── package.json
│
└── README.md
```

**约定**：`plugin/cccms` 只放**框架级基础功能**；一切业务功能一律新建业务插件，禁止往 `cccms` 插件里塞业务代码。

---

## 五、快速开始

### 5.1 后端

```bash
cd server
composer install

# 1) 建库并导入（自行建库）
#    CREATE DATABASE cccms DEFAULT CHARSET utf8mb4;
#    导入 plugin/cccms/db/schema.sql
#    导入 plugin/cccms/db/seed.sql

# 2) 同步菜单与按钮节点
php webman cccms:menu-sync      # 目录/菜单（读 db/menu.php）
php webman cccms:perm-scan      # 按钮（扫描控制器注解）；加 --check 只校验不写库
php webman cccms:db-upgrade     # 已有库补新增表/列/索引（新装环境不需要）

# 3) 启动（默认 http://0.0.0.0:8787）
php windows.php          # Windows
php start.php start      # Linux / macOS
```

- 数据库连接：`plugin/cccms/config/database.php`，支持 `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_PORT` 环境变量覆盖。
- JWT 密钥：`plugin/cccms/config/auth.php` 的 `secret`，**生产环境务必替换**（HS256 要求 ≥ 32 字节）。
- 字段级 `encrypt` 密钥：同文件 `data_encrypt_key`。

### 5.2 前端

```bash
cd frontend
npm install
npm run dev      # http://localhost:5173
npm run build    # 产物 dist/
```

开发期 `/api` 由 Vite 代理到 `http://127.0.0.1:8787`（见 `frontend/vite.config.ts`），前端统一以 `/api` 前缀调用接口。

### 5.3 默认账号

`admin` / `admin123`（首次登录后请立即修改）。

### 5.4 Docker 部署

```bash
cd server
docker compose up -d --build   # 容器暴露 8787，映射 ./ 源码
```

镜像基于 `php:8.3-cli-alpine`，已启用 `pdo_mysql` / `pcntl` / `opcache`。

---

## 六、架构与核心设计

### 6.1 请求生命周期

```
请求
 └─ Cors                 跨域
 └─ CheckLogin           解析 token → $request->user（UserContext）；#[NoLogin] 跳过
 └─ CheckAuth            ① 认证 401 → ② 授权 403 → ③ 方法 405
 └─ ResponseEncode       ④ 编码白名单 406，确定本次响应编码
 └─ Controller
     └─ Logic            业务逻辑（数据权限已下沉到 Model 层，见 6.4）
         └─ Model        数据访问
 └─ OperationLog         操作日志（写操作记录）
 └─ Result               按编码渲染 json / jsonp / xml / view
```

顺序刻意设计为 **401 → 403 → 405 → 406**，避免越权者靠错误码探测接口结构。

### 6.2 鉴权注解体系（唯一声明机制）

全部采用 PHP 8 原生属性（Attribute），**只有一种声明机制**，且一律使用**命名参数**。

4 个属性位于 `server/plugin/cccms/support/attribute/`：

| 属性 | 可写位置 | 参数 | 说明 |
|------|----------|------|------|
| `Permission` | 方法 | `slug`（必）/ `title`（必）/ `sort` / `group` | 业务接口：需登录 + 需权限 |
| `NoAuth` | 方法 | `title` | 登录即可，无需按钮权限（如个人资料） |
| `NoLogin` | 类 / 方法 | `title` | 完全匿名（登录页、验证码） |
| `Restrict` | 类 / 方法 | `methods` / `encode` | 约束请求方法与响应编码 |

```php
#[Permission(slug: 'cccms:user:index', title: '用户列表')]
#[Restrict(encode: ['json', 'xml'])]
public function index(Request $request): Response {}

#[Permission(slug: 'cccms:user:save', title: '用户保存')]
#[Restrict(methods: ['POST'])]
public function save(Request $request): Response {}

#[NoAuth(title: '修改密码')]
public function profile(Request $request): Response {}

#[NoLogin(title: '登录')]
public function login(Request $request): Response {}
```

**权限节点标识 = 显式 slug**，命名约定 `{插件}:{模块}:{动作}`，至少 3 段，全小写：

- 正则：`^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*){2,}$`
- 第一段固定为插件名，使「权限标识 / 后端控制器 / 前端页面」三者可互相推导
- 最后一段是动作，其之前的全部段拼起来 = 归属菜单 slug（最长前缀匹配）
- 全局唯一，由 `perm-scan` 强制校验

**鉴权规则矩阵（fail-closed）**：

| 方法上的属性 | 需登录 | 需权限 |
|--------------|--------|--------|
| 无任何属性 | — | — | **拒绝**（500 + 「接口未声明权限」） |
| `#[Permission]` | ✅ | ✅ 校验 slug |
| `#[NoAuth]` | ✅ | ❌ |
| `#[NoLogin]` | ❌ | ❌ |
| `#[Restrict]` | 由其它属性决定 | 只追加约束（405/406） |

互斥校验：`Permission` / `NoAuth` / `NoLogin` 三者至多出现一个；类级 `#[NoLogin]` 的类内不得出现 `#[Permission]`。

### 6.3 角色模型与继承

- **超管** = 持有 `sys_role.code = 'super_admin'` 的角色（非 `id === 1` 硬编码）。该角色禁止编辑/删除，绕过权限校验与数据权限，且不写入权限缓存。
- **角色继承（方案 A：子角色继承父角色）**：子角色可用节点 = 自身节点 ∪ 祖先链节点（`sys_role.parent_id` 向上递归）。新增子角色不影响父角色（无隐式扩权）；`data_scope` 继承父角色。授权界面继承节点只读；保存时强制**环检测 + 深度上限 5**。

### 6.4 数据权限

**由模型层自动注入**：`BaseModel` 挂了全局查询作用域（`$globalScope = ['dataScope']`），
只要查询走模型，行级范围就自动生效 —— 列表、详情、`count()`、以及按 id 直调的编辑 / 删除都被覆盖，
业务层不需要（也不应该）再手写判定。

每张表的「数据范围怎么算」写在模型里（`$dataScope`）：

| 模型 | 声明 | 语义 |
|------|------|------|
| `User` | `owner = id`、`dept` 走 `sys_user_dept` | 仅本人 = 自己这个账号；本部门 = 所属部门成员 |
| `Dept` | `owner` / `dept` = 我所属部门（及子树） | 部门页按范围收窄 |
| `File` | `owner = create_by`、`dept` 落到可见部门成员 | 我上传 / 本部门上传的附件 |
| `OperationLog` | `owner = user_id`、`dept` 落到可见部门成员 | 自己的 / 本部门的操作日志 |
| `Crontab` | `no_baseline` | 无归属列，隔离交给自定义规则 |
| 其余（角色 / 岗位 / 菜单 / 配置 / 字典 / 分类 / 规则 / 关联表） | `false` | 不参与（基础设施与组织架构数据） |

不声明又确实没有 `create_by` / `dept_id` 的表会直接报「列不存在」——**这是有意的**：
逼你把「这张表的数据范围怎么算」写清楚，而不是静默放行。

**角色级预置范围**（`sys_role.data_scope`）：

| 值 | 含义 |
|----|------|
| 1 | 全部数据 |
| 2 | 本部门及以下 |
| 3 | 本部门 |
| 4 | 仅本人 |
| 5 | 自定义（只读 `sys_data_rule`） |

**`sys_data_rule` 行级 + 字段级规则**：

- **绑定维度**：用户 / 岗位 / 部门（含下级）/ 角色；四项都空 = 全局规则
- **单条规则内部的组合方式**由 `bind_mode` 决定：`or`（默认）= 命中**任意一项**即生效；
  `and` = **所有已填写**的维度都要命中（未填写的维度不参与判断 —— 否则只填一项时永远不会命中）
- ⚠️ `or` 比直觉更宽：同时选「用户 A」+「岗位 P」是「A」**或**「所有 P 的人」，
  不是「A 且 处于 P」。要后者请把「绑定关系」改为「全部命中（且）」
- **多条规则之间是「与」**：命中当前用户的每条行级规则各往查询里加一个条件，所以多条收窄规则叠加只会更窄；
  某条不命中的规则不参与，也不会放宽其它规则（行级规则是 AND，**先后顺序没有影响**）
- **字段级动作是按顺序依次处理**的：同一字段被多条字段级规则命中时结果取决于处理顺序，
  运行时固定按规则 **id 升序**（创建顺序）执行，因此可复现；这类组合会被规则体检的
  「同字段多动作」提示（`mask` + `encrypt` 叠加会产出「被掩码的密文」）
- 规则**没有排序字段**：它不影响规则如何生效，只影响列表行序，列表里也不展示 —— 去掉以免被误当权重
  （列表固定按 id 升序输出）
- 角色绑定是**精确匹配**：只看 `sys_user_role` 里的直接分配，既不带上子角色，也不继承上级角色
  （与鉴权用的 `AuthService::effectiveRoleIds()`「含祖先」口径不同，属已知差异，见 `待办.md`）
- 目标表：`table_name` 指定规则作用于哪张表（空 = 不限表）；受控表由 `sys_data_scope_table` 维护（规则页「受控表」可视化增删/停用）
- 行级操作符白名单：`=` `!=` `<>` `>` `>=` `<` `<=` `like` `in` `between`；字段名正则限 `[A-Za-z_][A-Za-z0-9_]*`，取值全部参数化
- 字段级动作：`hidden`（不出参）/ `readonly`（入参强制剔除）/ `mask`（掩码如 `138****8888`）/ `encrypt`（AES-256-GCM 密文，前端解密）
- 字段级规则的落点也在模型层：**出参**由 `BaseModel::toArray()` 统一处理，**入参**由 `ScopedQuery`（`BaseModel::$query`）在写库前剔除全部四种动作 —— 所以「掩码值被表单原样回填」不会污染数据，而「看不见真实值 ⇒ 一定改不了」
- 动态取值：`value_type = dynamic` 的 `{变量}` 按当前用户解析（`{user.id}` / `{dept.ids}` / `{dept.subtree}` / `{post.ids}` / `{role.ids}`）
- 自定义档（data_scope=5）：**没配规则 / 一条都没命中 ⇒ 看不到任何数据**（fail-closed，无条件成立）。
  「自定义规则没填 = 没有任何权限」是刻意语义：模型参与数据权限就说明这张表按范围隔离，
  此时「没有规则」只能是「无权限」。某张表压根不该隔离的正确做法是模型声明 `$dataScope = false`
- **多条规则之间是「与」**：命中同一个人的每条行级规则各加一个条件，因此两条语义相反的规则
  会把人过滤成「什么都看不到」（`WHERE id = 1 AND id != 1`）—— 安全但静默，
  用 `cccms:data-rule-check` 或列表的「检测」列可以发现
- **规则体检**（`support/RuleConflict.php`）：条件互斥 / 同字段多动作 / 恒不生效 / 表未受控；
  只报「可证明为空」的组合（同绑定项或含全局规则、静态取值、可判定的操作符），判不了就不下结论
- 超管不受数据权限限制；数据权限变更 → 版本号 `INCR` 失效所有相关缓存

**性能**：每次请求只解析一次用户维度数据（角色 / 岗位 / 部门 / 规则 / 受控表），
缓存键是 `UserContext` 实例（`WeakMap`），随请求销毁 —— 权限变更下一个请求即生效。
实测同一请求内 10 次模型查询：数据权限相关 SQL **0 条**（去掉缓存对照为 50 条）。

**边界与逃生口**：

- 超管不受限；CLI（定时任务 / 命令 / 迁移）、登录链路、`#[NoLogin]` 路由没有当前用户 → 自动跳过；
- 需要看**全量**数据的地方显式跳出：唯一性 / 存在性校验用 `Model::withoutGlobalScope()`，
  回收站与「已删行也要同步」的场景用 `Model::withTrashed()`，配置界面的候选项同理。
  显式写出来，好过隐式生效。

**已接入**：

| 表 | 预设基线 | 登记受控表（可配自定义规则） |
|---|---|---|
| `user` / `file` / `log` / `crontab` | ✓ | ✓ |
| `dept` | ✓（部门页按「本部门及以下」收窄） | ✓（可选：登记后才可配自定义规则） |

- `dept` 是**可登记可不登记**的典型：不登记也受预设基线保护，登记后才会出现在「目标表」下拉里。
  给它配字段级规则（如隐藏 `name`）会让部门树显示空白名，配之前想清楚。

- **登记受控表**只影响「能不能给它写自定义规则」：不登记的表仍受预设基线保护，
  只是「目标表」下拉里不出现它（避免给未接入的表配出永远不生效的规则）。
  入口：数据权限规则页 →「受控表」按钮；`php webman cccms:data-scope-check` 会验证登记的表确实接入了。
- 工作台统计与各自列表页口径一致（`user` / `dept` / `log` / `file` 的计数按当前用户范围聚合），
  否则会出现「列表只能看到 3 条、工作台显示 1000 条」的绕行泄漏。
- `sys_data_scope_table` 中其余已登记的表由对应插件自行接入。

**新插件接入（三步）**：

1. **模型声明**：代码生成器产出的模型已自带声明；手写模型要么参与，要么显式 `protected $dataScope = false;`
2. **登记受控表**（只有需要配自定义规则时才要）：数据权限规则页 →「受控表」→ 添加
3. **跑检查**：`php webman cccms:data-scope-check` —— 控制器直连库、受控表未模型化、
   Logic 里裸查受控表都会报错；接入部署 / CI 流程就不会漏

### 6.5 统一响应 `Result`

统一信封 `{code, message, data}`，支持 `json` / `jsonp` / `xml` / `view` 四种编码。请求侧以 `?format=xxx` 选择，缺省取白名单第一项；不在白名单 → **406**（fail-closed）。

### 6.6 软删除与回收站

业务主数据的删除都是软删除（写 `delete_time`），**过滤由模型层自动完成**：
`BaseModel` 用了 think-orm 的 `SoftDelete` trait，模型查询自动排除已删行，
不再依赖「每个读取入口记得调用过滤」这种约定。

- 回收站视图：`Model::onlyTrashed()`（列表接口带 `trashed=1` 切换数据源）
- **要操作已删行必须 `Model::withTrashed()`**：恢复、彻底删除、菜单同步里「复用已删节点」都属此类；
  漏了会静默变成 0 行（模型默认条件是 `delete_time IS NULL`，与 `whereNotNull('delete_time')` 自相矛盾）
- 软删 / 恢复：`Model::destroy($ids)` / `$model->restore()`；表里没有 `delete_time` 的模型声明 `$deleteTime = false`
- 前端入口：各模块页面表格右上角图标，操作列变「还原 / 彻底删除」
- `support/SoftDelete.php` 只服务**查询构造器直查**（`Db::name()`）：回收站模块要跨表操作已删数据，
  `AuthService` / `DataScope` / 调度进程等基础设施同理 —— 能用模型的地方优先用模型
- 软删除 + 回收站：user / role / dept / post / dict_type / dict_data / category / crontab / data_rule / file / menu
- 不软删：关联表、log（保留天数清理）、config
- 唯一键不做特例（软删后仍占用，新增给可读提示）；附件彻底删除才清物理文件；树结构防御孤儿节点

### 6.7 附件与存储

- 一期本地驱动，落盘 `server/public/storage/YYYYMMDD/`，同域 `/storage/...`
- URL 不硬编码，统一经 `FileStorage::url($path)` 生成（前缀来自 `plugin.cccms.filesystem.url_prefix`，切 CDN 无需迁移历史数据）
- 二期多驱动：`StorageManager` 解析 `local` / `oss` / `cos` / `qiniu`，统一接口 `upload / url / delete`
- 上传安全：扩展名 + 大小上限（默认 10MB）+ 随机重命名；`public/storage` 必须关闭 PHP 解析

### 6.8 定时任务 / 代码生成 / 前台应用

- **定时任务**：`plugin/cccms/process/Crontab` 每秒 tick；`CronMatcher` 自实现**六段（秒 分 时 日 月 周）**；执行白名单只跑实现 `CrontabTask` 接口的类（杜绝 RCE）；前端宝塔式 `CronEditor` 引导 + 运行预览
- **代码生成**：按库表生成 Model / Logic / Controller / 路由 / 前端 API / 前端页面 6 类产物，自动登记菜单，生成即符合 fail-closed
- **前台应用**：独立 `plugin/index`，路由前缀 `/site/*`，与后台中间件隔离；复用 `BaseController` 统一信封，已实现 `GET /site/ping`、`GET /site/home`

---

## 七、核心库表

| 表 | 说明 |
|----|------|
| `sys_user` | 用户 |
| `sys_role` | 角色（`code` 唯一、`data_scope`、`parent_id` 继承） |
| `sys_user_role` | 用户↔角色（直连） |
| `sys_menu` | 菜单 + 权限节点树（`type` 1目录/2菜单/3按钮，`node` = slug，唯一索引） |
| `sys_role_node` | 角色↔节点授权（`node` = slug） |
| `sys_dept` | 部门（无限级） |
| `sys_user_dept` | 用户↔部门（多对多） |
| `sys_dept_role` | 部门↔角色 |
| `sys_post` | 岗位 |
| `sys_user_post` | 用户↔岗位 |
| `sys_data_rule` | 数据权限规则（行级 + 字段级；`dept_ids` 为 JSON 数组，`table_name` 指定目标表） |
| `sys_data_scope_table` | 数据权限受控表（哪些表可配数据权限） |
| `sys_dict_type` / `sys_dict_data` | 数据字典 |
| `sys_category` | 通用分类（`module` 区分 dict / file，可层级） |
| `sys_config` | 系统配置（键值 + 控件类型 + 分组；种子内置 5 组 24 项） |
| `sys_log` | 操作日志 |
| `sys_crontab` / `sys_crontab_log` | 定时任务（二期） |
| `sys_file` | 附件（`category_id` 归属分类） |

---

## 八、系统配置项（`sys_config`）

种子内置 5 组 24 项，**全部已接入实际逻辑**（保存后立即生效，无需重启）。读取：`SysConfig.php`，取数顺序 Redis（TTL 300s）→ MySQL，保存后 `flush()` 立即失效（刻意不做进程内缓存）。部分示例：

| 配置键 | 生效位置 |
|--------|----------|
| `system.name` / `system.logo` / `system.icp` / `system.copyright` | 站点品牌与页脚（`GET /config/ui`） |
| `system.maintenance` / `system.maintenance_notice` | 维护模式（非超管立即失效 + 503 + 无法登录） |
| `ui.theme_mode` / `ui.theme_primary` / `ui.container_width` / `ui.page_size` / `ui.tags_view` | 前端默认主题与布局 |
| `security.login_captcha` / `login_fail_limit` / `login_fail_window` / `token_ttl` / `password_min_length` | 登录安全 |
| `upload.max_size` / `upload.ext_allow` / `upload.storage_driver` / `upload.url_prefix` | 附件上传 |
| `log.record_read` / `log.keep_days` / `log.auto_clean` | 操作日志 |

新增配置项：`db/seed.sql` 加一行 + 消费处调用 `SysConfig::getInt/getBool/getString/getList('your.key', 默认值)`。

---

## 九、安全硬约束（不可省略）

| # | 约束 | 原因 |
|---|------|------|
| 1 | 数据权限字段名与操作符必须白名单 | `sys_data_rule` 条件字段会进 SQL where，需封死注入面 |
| 2 | `public/storage` 禁止解析 PHP | 同域上传目录可执行脚本 = 任意代码执行 |
| 3 | Token 必须与用户状态强关联 | `CheckLogin` 每次请求从 DB 重建用户上下文，禁用/删除用户立即 401 |
| 4 | `#[NoAuth]` 不下放给下拉/选项接口 | 避免任意登录用户拿全量数据 |
| 5 | 角色继承必须防环 + 限深 | `parent_id` 成环会让权限解析死循环；深度上限 5 |
| 6 | 登录失败限流 + 密码策略 | Redis 计数，连续失败锁窗口；初始密码强制修改 |

---

## 十、开发规范

### 10.1 新增一个业务接口

```php
#[Permission(slug: 'cccms:user:save', title: '用户保存')]
#[Restrict(methods: ['POST'])]
public function save(Request $request): Response { /* ... */ }
```

改完执行 `php webman cccms:perm-scan`，按钮节点自动挂到最长前缀匹配菜单下；前端按钮用 `v-auth="'cccms:user:save'"` 控制显隐。

### 10.2 前端页面约定

1. **组件名必须等于菜单 slug**（keep-alive 精确缓存依赖）：`defineOptions({ name: 'cccms:user' })`
2. 列表页统一用 `ArtTable` + `useTable`，根元素 `.art-fill`；表单页用 `.art-scroll`
3. 图标用字符串名：`<ArtIcon name="icon-user" />`；菜单图标候选来自 `MENU_ICON_OPTIONS`
4. 颜色只用设计 token `var(--art-*)`，不写死，否则暗色模式花
5. 权限按钮 `v-auth="'cccms:user:save'"`，支持数组（命中任一即显示）
6. 回收站：`useRecycle('user', { reload })` 必须写在 `useTable` 之前；列表接口带 `trashed` 参数

### 10.3 代码风格（必守）

**后端 PHP**

- 文件头 `declare(strict_types=1);`，namespace `plugin\cccms\...`
- 控制器极薄：只解析请求 → 调 Logic → `return $this->ok(...)`；无属性即 fail-closed
- 注解一律命名参数；写操作补 `title` 给操作日志
- Logic 用 `Db::name('user')`（查询构造器）；软删除入口必须 `SoftDelete::apply()`
- 模型 `$name = 'user'`（不含 `sys_` 前缀）；时间戳交数据库默认值，业务层不手动写
- 抛错用 `ApiException('msg', code)`；JSON 字段用 ORM JSON 类型，不逗号拼接

**前端 Vue/TS**

- 列表页文件一律 `index.vue`，`defineOptions({ name: 'cccms:user' })`（必须 = 菜单 slug）
- 颜色只用 `var(--art-*)`；权限 `v-auth`；图标 `<ArtIcon name="..." />`
- 树形页面（dept/menu）：`ArtSplitView` + `ArtTreePanel` + `ArtTable tree :pagination="false"`

---

## 十一、常用命令

```bash
php webman cccms:perm-scan            # 扫描属性 → 校验 + 同步 sys_menu 按钮节点
php webman cccms:perm-scan --check    # 只校验不写库（CI 用）
php webman cccms:menu-sync            # 同步 db/menu.php 的目录/菜单节点
php webman cccms:db-upgrade           # 已有库补新增表/列/索引（幂等，可重复执行）
php webman cccms:data-scope-check     # 校验数据权限接入（控制器直连库 / 受控表未模型化）
php webman cccms:data-rule-check      # 体检数据权限规则（条件互斥 / 同字段多动作 / 恒不生效 / 表未受控）
                                      # 有「条件互斥」时退出码为 1，可接 CI
```

后台顶栏「系统同步 / 清理缓存」按钮（`cccms:config:refresh`）提供 `menu` / `perm` / `cache` / `all` 四种范围，与 CLI **共用同一份实现**，同步完自动重挂前端动态路由并刷新当前用户权限。

---

## 十二、项目状态

- **一期（P0）全部完成**：Webman 插件骨架、4 注解 + 中间件链、统一响应、15+ 表与种子、`perm-scan` / `menu-sync` 校验、RBAC（含继承）+ 组织、附件管理、操作日志、基础防护、前端 11+ 模块页面（Element Plus + Tailwind 4 重做 UI）、工作台统计。
- **二期（P1）全部完成**：定时任务、代码生成器、多存储驱动、前台 `plugin/index`。
- **数据权限已下沉到模型层**：行级范围（`BaseModel::$globalScope`）、字段级出参（`toArray()`）、
  字段级入参（`ScopedQuery`）三处自动生效；`user` / `dept` / `file` / `crontab` / `log` 已接入，
  其余表按需登记受控表即可配自定义规则。
- **软删除已下沉到模型层**：think-orm 的 `SoftDelete` trait，查询自动排除已删数据；
  `support/SoftDelete.php` 仅服务查询构造器直查（回收站模块与基础设施）。
- **遗留**：`config` / `menu` / `category` / `dict` / `recycle` / `generator` 几个模块仍是查询构造器直查 ——
  它们**不参与数据权限**（模型声明 `false`），迁移只统一查询入口，可按需再做。
  另：受控表里登记的下游插件表（`ks_*`）由对应插件自行接入，本仓库不负责。

> 本仓库为全新工程，不迁移、不兼容老 cccms（ThinkPHP 8）数据；鉴权全面采用 PHP 8 原生属性，仅有一种声明机制。
