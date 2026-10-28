# 常用命令与运维 · CLI 命令

> 所属：[常用命令与运维](index.md)；本卷覆盖第一 ~ 第二章。

---

## 一、CLI 命令

所有命令通过 Webman 控制台执行，路径以 `server/` 为基准。

### 1.1 `cccms:perm-scan` — 扫描权限注解 → 同步按钮节点

```bash
php webman cccms:perm-scan            # 校验 + 写库（同步 sys_menu 的 type=3 按钮节点）
php webman cccms:perm-scan --check    # 只校验不写库（CI 用）
```

- 扫描各业务插件控制器的 4 个注解，校验 5 项（必须声明注解、互斥、slug 格式、slug 唯一、Restrict 取值合法）。
- 同步策略：**只增不删**；已存在节点仅「恢复软删」+「纠正归属父节点」，**不覆盖管理员改过的 title / sort**。
- 会报告「僵尸节点」（代码里已无对应注解的按钮节点；回收站里的不算）。
- 退出码：校验有错 / 写库异常 → `1`；正常 → `0`。

### 1.2 `cccms:menu-sync` — 同步目录 / 菜单

```bash
php webman cccms:menu-sync
```

- 读取各插件的 `db/menu.php`，把目录 / 菜单（type=1/2）写入 `sys_menu`。
- `['slug' => 'xxx', 'remove' => true]` 是废弃占位：同步会连带删除该节点（含子树）与对应角色授权。
- 找不到归属父节点（声明缺失）→ 报错。
- 退出码：异常 → `1`；正常 → `0`，输出 `created / updated / removed` 与按插件明细。

### 1.3 `cccms:db-upgrade` — 执行插件 SQL

```bash
php webman cccms:db-upgrade
```

- 可重复执行，顺序固定：核心 `db/schema.sql` → 各插件 `db/schema.sql` →
  **结构迁移** `db/migrations/*.sql` → 核心 `db/seed.sql` → 各插件 `db/seed.sql`。
- `schema.sql` 全是 `CREATE TABLE IF NOT EXISTS`，只负责「建缺失的表」；
  **已存在表的结构变更走 `db/migrations/`**：文件名即版本号（`YYYYMMDD_HHMMSS_描述.sql`），
  按版本号全局排序、每个版本只执行一次，记录在 `sys_schema_migration`
  （含 SHA1，文件被改动只告警不重跑）。
- 新增结构变更的做法：改 `schema.sql`（新装环境一次建全）+ 新增一个迁移文件（存量库补齐）。
  迁移文件里「重复列 / 重复索引」会被容忍为「已生效」，因此两边写同一份结构是安全的。
- 多租户隔离口径见 [06-数据权限 §十二](../06-数据权限.md#十二多租户租户是硬边界档位是租户内的软范围)。
- 退出码：任一 SQL 或迁移失败 → `1`；正常 → `0`。

### 1.4 `cccms:data-scope-check` — 校验数据权限接入

```bash
php webman cccms:data-scope-check
```

- R1：`app/controller/**` 不得出现 `Db::name` / `Db::table` / `Db::query`。
- R2：受控表必须有模型且不得声明 `$dataScope = false`；Logic 层不得裸查受控表。
- R3（P1-1）：业务插件表（`plugin/{name}/db/schema.sql` 声明、不带 `sys_` 前缀）必须
  「登记受控表 / 自带 `tenant_id` 列 / 在 `data_scope.exempt_tables` 显式豁免」三选一，
  否则报 `error` —— 这类表默认既不受租户边界约束、数据规则也不生效，界面上看不出异常。
- R4（P2-6）：模型声明了 `$tenantScope = true`，其表必须在 `TenantContext::TABLES` 里，
  否则模型查询（带租户条件）与 `Db::name` 直查（不带）会给出两种结果。
- 主代码里找不到模型的受控表（如下游插件登记的 `ks_*`）只报 `NOTICE`，不计入错误（由 R3 兜住）。
- 只检查**当前库真实存在**的插件表：插件声明了但没建表不报错；同库其它系统的表（未在任何
  插件 `schema.sql` 里出现）不参与判定。
- 豁免写法：`DATA_SCOPE_EXEMPT_TABLES=ks_sync_cursor,ks_import_staging`（见 `.env.example`）。
- 退出码：存在 `error` 级 → `1`；否则 `0`。

### 1.5 `cccms:data-rule-check` — 数据权限规则体检

```bash
php webman cccms:data-rule-check
```

检查四类问题：

| 类别 | 严重度 |
|------|--------|
| `row_unsat` 行级条件互斥（命中者看不到任何数据） | 错误 |
| `field_dup` 同字段多个字段级动作 | 提示 |
| `never_hit` 取值不完整导致规则被跳过 | 提示 |
| `not_guarded` 目标表未登记受控表 | 提示 |

- 退出码：存在「条件互斥」→ `1`；无规则或仅提示项 → `0`。

### 1.6 `cccms:write-guard-check` — 校验写入口是否声明字段白名单

```bash
php webman cccms:write-guard-check
```

防 **Mass Assignment**：写接口把 `$request->post()` **整数组**交给 Logic，可写字段集合原本是隐式的
（靠模型声明 + `ScopedQuery` 剔除 `hidden` / `readonly` / `mask` / `encrypt` 兜底），
新增字段时容易漏判。本命令要求每个「把数组形参整行写库」的写方法显式声明白名单：

```php
private const FIELDS = ['username', 'nickname', 'email', 'status', 'remark'];

public static function create(array $data): int
{
    $data = FilterInput::only($data, self::FIELDS);   // 白名单外的键一律丢弃
    ...
}
```

- 判定范围：各业务插件 `app/logic/**` 中，数组形参出现在
  `insert` / `insertGetId` / `insertAll` / `update` / `save` / `replace` 实参里的方法。
- 判据：方法体内有 `FilterInput::only(`，或**委托给同类中的一个方法**（如 `self::prepare($data)`）而它里面调用了它。
- 逃生口：确需整数组透传时在方法体写 `// @write-guard-ignore` 并说明理由。
- 退出码：存在 `error` 级 → `1`；否则 `0`。

> 能力边界见 §12.4「检查命令的能力边界」。

### 1.7 `composer test` — 后端单元测试

```bash
cd server
composer test                 # 等价于 php tests/run.php
php tests/run.php             # 直接运行
php tests/run.php Cron        # 只跑名字包含 Cron 的用例（过滤）
```

- 零依赖运行器（`tests/run.php`），**不需要 PHPUnit**；用例在 `tests/cases/`。
- 覆盖：Cron 表达式匹配与下次运行时间、权限节点 slug 规范、CSV 编码与注入防护、数据规则体检、
  真实 IP 溯源（`ClientIpTest`）、MIME 探测（`StorageMimeTest`）、配置缓存单飞（`SysConfigLockTest`）、
  写入口白名单（`WriteGuardTest`），以及**集成用例**：鉴权矩阵（`AuthMatrixTest`）、
  数据权限三档（`DataScopeIntegrationTest`）、登录失败双维度锁定（`LoginThrottleTest`）、
  租户硬边界机制（`TenantBoundaryTest`）、回收站 `withTrashed` 陷阱（`RecycleIntegrationTest`）。
- **需要数据库 / Redis 的用例在服务不可用时自动跳过**，因此本地无库也能安全执行。
- **CI 必须让集成用例真跑**：设 `CCCMS_TEST_REQUIRE_SERVICES=1` 后，「跳过」升级为「失败」
  —— 否则 CI 里服务没就绪时会静默全绿，登录双维度、租户边界这些安全链路等于没有回归保护
  （`.github/workflows/php.yml` 已默认设置）。
- 退出码：有失败 → `1`，否则 `0`。

### 1.8 `composer analyse` — PHPStan 静态分析

```bash
cd server
composer analyse              # 等价于 vendor/bin/phpstan analyse --memory-limit=1G
```

- 配置在 `phpstan.neon`：**level 5**，只扫 `plugin/cccms`（本仓库维护的框架级基础功能；
  业务插件由使用方各自维护）。
- 既有问题冻结在 `phpstan-baseline.neon`（当前 103 条，集中在 Webman `Request` 动态属性、
  think-orm facade 与云 SDK 的魔术方法）。**新增代码不得往 baseline 里加条目**：
  需要忽略时优先补类型标注，其次在 `phpstan.neon` 的 `ignoreErrors` 里写**带理由**的精确规则。
- `reportUnmatchedIgnoredErrors: false`：修掉问题后 baseline 条目失效不会反过来报错，
  因此不需要每次改代码都重生成 baseline。
- 退出码：有错误 → `1`，否则 `0`。

### 1.9 `composer cs:check` / `cs:fix` — 后端代码风格

```bash
cd server
composer cs:check   # 只检查（CI 用，等价 php-cs-fixer fix --dry-run --diff --using-cache=no）
composer cs:fix     # 就地格式化
```

- 配置在 `.php-cs-fixer.dist.php`：以 `@PSR12` 为基线，只补充与现有写法一致的少量规则
  （短数组、单引号、`use` 排序与去重、多行数组尾逗号）。
- **校验范围是 `plugin/` 与 `tests/`**，刻意不含 `server/config`、`server/support`、`server/app`
  这些 Webman 模板自带的文件：格式化它们会在 `cccms:update` 的三方哈希比对里变成
  「本地已改」而被永久跳过，反而丢掉框架更新。
- ⚠️ 仓库根有 `.gitattributes`（`* text=auto eol=lf`）：换行符统一为 LF，
  否则 php-cs-fixer 的 `@PSR12`（含行尾规则）会在 Windows（`core.autocrlf=true`）
  把**每个文件**都判成需要格式化，而 CI 通过，形成假信号。
- 退出码：有不符合项 → `1`，否则 `0`。

### 1.10 `cccms:update` — 从上游仓库同步框架更新

```bash
php webman cccms:update --init                 # 首次：建立基线（记录当前代码基于的上游版本）
php webman cccms:update --check                # 只比对不写文件（CI / 预览）
php webman cccms:update                        # 同步：覆盖「本地没改过」的文件，冲突只报告
php webman cccms:update --force                # 冲突文件也覆盖（覆盖前自动备份）
php webman cccms:update --prune                # 删除上游已移除且本地未改动的文件（先备份）
php webman cccms:update --force-all            # 强制对齐上游：连「本地定制」「本地删除」也覆盖/恢复，并删除上游已移除（全部先备份）
php webman cccms:update --ref=v0.0.2           # 指定目标版本（分支 / tag / commit）
php webman cccms:update --source=gitee         # 指定同步源（gitee / github，默认取配置）
php webman cccms:update --tags                 # 列出远端可用版本
php webman cccms:update --status               # 只输出状态摘要（供脚本 / 监控消费）
php webman cccms:update --json                 # 机器可读输出
php webman cccms:update --no-fetch             # 离线，使用本地缓存的上游仓库
```

> 也有图形界面：后台「系统设置 → 自动升级」，提供版本切换、变更文件与行数、提交记录、
> 一键升级（与命令共用同一套实现），适合日常操作。

**解决什么**：下游基于某个版本开发业务插件后，框架自身仍在演进。本命令用
「基线 / 本地 / 上游」三方哈希比对，机械地判断哪些文件能安全覆盖，无需手工 diff。

| 分类 | 条件 | 处置 |
|------|------|------|
| 上游新增 | 上游有、本地无、基线也没有 | 直接写入 |
| 可安全覆盖 | 本地未改、上游已改 | 直接覆盖 |
| 本地已改(保留) | 本地改了、上游没动 | 保留（`--force-all` 才覆盖） |
| 本地已删除(保留) | 本地删了（如重命名）、上游仍有 | 保留，不上演「复活」（`--force-all` 才恢复） |
| 冲突(需合并) | 两边都改了 | 只报告，`--force` 才覆盖 |
| 上游已移除 | 上游删了、本地未改 | 只报告，`--prune` 才删除 |

- **不碰本地独有文件**（既不在上游也不在基线里），业务插件天然安全。
- **下游落后太多、想整体对齐上游时**：`--force-all`。它把「本地已改」「本地已删除」也覆盖 / 恢复、
  并删除「上游已移除」的文件（全部先备份）。这是**有损操作**（会丢弃本地对框架文件的改动），
  只应在确认「那些差异其实是落后、不是定制」时使用。
- 所有覆盖 / 删除都先按原相对路径备份到 `runtime/cccms-upgrade/backups/<时间戳>/`，
  同目录下有 `report.txt`；把该目录内容按相对路径覆盖回去即可回滚。
- 不依赖本地 git 仓库：只在 `runtime/cccms-upgrade/repo/{源}` 按源缓存一份上游仓库，
  本地有无未提交改动都不影响，也不会污染本地版本历史。
- 退出码：存在未处理的「冲突(需合并)」→ `1`；正常 → `0`；执行失败（git 不可用等）→ `2`。

- **仅限本地开发环境使用**。线上是「已构建」形态：前端产物在 `public` 下、没有 `frontend/` 源码目录、
  `docs/` 等目录也不在 —— 与仓库目录结构不一致，按仓库相对路径覆盖会写出线上不该有的文件、
  也覆盖不到线上真正要用的那份代码。因此两道门槛都过才允许执行：

  | 门槛 | 说明 |
  |------|------|
  | 总开关 `enable` | **默认 `false`**；本地开发时在 `.env` 里 `CCCMS_UPGRADE_ENABLE=true` 打开 |
  | 目录自检 | 仓库根必须存在 `frontend/package.json`（`Environment::DEV_MARKER`），否则一律拒绝 |

  被拦截时：页面顶部给出醒目提醒并**禁用全部升级按钮**（接口侧同样拦截），`cccms:update` 直接失败
  （退出码 `2`），提示「当前不是本地开发环境（未发现 frontend/package.json）」。自检结果有 60 秒进程内缓存，
  部署形态变化后最多 1 分钟生效。

配置见 `plugin/cccms/config/upgrade.php`：`enable`（总开关，默认 `false`）、`remotes`（同步源，可配多个镜像，如 Gitee 国内 /
GitHub 国外）、`default_source`（默认源，默认 `gitee`）、`track`（跟踪分支 / tag）、`base`（基线版本）、
`exclude`（永不覆盖的路径）、`git`（可执行文件路径）。
基线记录的是**内容哈希**，与源无关，因此切换镜像不需要重建基线。
公开仓库可直接匿名拉取（Gitee / GitHub 均实测通过），不需要任何凭据；私有仓库在 URL 里带令牌即可
（`https://<账号>:<令牌>@gitee.com/<owner>/<repo>.git`），接口响应与报错里的 URL 会自动打码。
同步只用到 `clone` / `fetch` / `rev-parse` / `ls-tree` / `diff` / `reset` 这些老命令，且**不使用
`git -C <dir>`**（该选项需 git 1.8.5+），目录切换一律通过进程工作目录 ——
因此 CentOS 6/7 自带的 git 1.7.x 也能跑（仍建议 2.x）。
环境变量：`CCCMS_UPGRADE_SOURCE` / `CCCMS_UPGRADE_TRACK` / `CCCMS_UPGRADE_BASE` /
`CCCMS_UPGRADE_GIT` / `CCCMS_UPGRADE_ENABLE` / `CCCMS_UPGRADE_URL_GITEE` / `CCCMS_UPGRADE_URL_GITHUB`，
统一写在 `server/.env`（示例见 `server/.env.example`）。`.env` 由 Webman **启动时**加载
（`Dotenv::createUnsafeImmutable`，已存在的同名系统环境变量优先），**改完要重启（或 reload）后端进程**才生效。

> **拉取失败怎么排查**：报错会自动附上**本机探测该源的 HTTP 状态（匿名 / 带凭据各一次）**与
> **git 版本**，例如 `；自检：访问该地址匿名 HTTP 401、带凭据 HTTP 403（已登录但无该仓库权限：
> 仓库可能是私有、账号未完成实名认证，或该账号不是仓库成员），git 1.8.3.1`。
> 对照：`401 / 200` 正常；`401 / 401` 凭据无效；`403 / 403` 本机 IP 被挡；两次都无响应 = 网络 / 代理问题。

> 首次使用前建议先给上游打 tag（`git tag -a v0.0.1 -m "..."`）；否则 `--init` 会提示
> 目标版本不存在，并回退到跟踪分支建立基线。

### 1.11 `cccms:log-archive` — 归档历史日志到对象存储

```bash
php webman cccms:log-archive                    # 归档早于配置天数的日志
php webman cccms:log-archive --dry-run          # 只统计，不落盘不删除
php webman cccms:log-archive --days=30 --batch=1000
php webman cccms:log-archive --path=/auth/login # 只归档登录日志
```

- **破坏性操作**：把选中的 `sys_log` 记录序列化为 JSONL(gzip) 上传到对象存储，**上传成功后**再按
  `id` 删除主库记录（先存后删，绝不出现「删了没存」）；按批执行，天然可重入。
- 归档前对 `params` / `result` 二次脱敏（复用 `LogRedactor`，与操作日志中间件同一份规则）。
- 与内置任务「清理历史日志」（`LogCleanTask`，直接删）的区别：本命令先把冷数据存下来，
  适合长期留存 / 合规审计。
- `--days` 有硬性下限 `7`（避免误删），`--batch` 收敛到 `[1, 50000]`。
- **审计链**：每批归档会额外上传一个同名 `<文件>.chain.json`，记录本批的段边界哈希
  （`head_hash` / `tail_hash` / `head_prev_hash`）、未入链条数（`unchained`）以及
  **归档时的实测结论** `broken_at`（非 0 = 那一刻链已不自洽，需要人工核查）。
  归档删的是链头部，删完会调 `LogChain::reanchor()` 把校验起点前移，避免把合法归档
  误报成断点。发现链不自洽时数据仍会归档（不能丢数据），但命令以退出码 `1` 结束并打出警告。
- 退出码：失败（存储不可用等）或链不自洽 → `1`；正常 → `0`。

配置见 `plugin/cccms/config/log.php` 的 `archive` 段：

| 键 | 默认 | 环境变量 | 说明 |
|----|------|----------|------|
| `days` | `30` | `LOG_ARCHIVE_DAYS` | 归档「创建时间早于 N 天」的记录 |
| `batch` | `1000` | `LOG_ARCHIVE_BATCH` | 每批条数 |
| `driver` | 空 | `LOG_ARCHIVE_DRIVER` | 存储驱动；留空 = 跟随 `upload.storage_driver` |
| `path` | `log-archive` | `LOG_ARCHIVE_PATH` | 对象存储路径前缀 |

**定时归档**：`db/seed.sql` 已预置任务「日志归档到对象存储」（`0 0 4 * * *` → `LogArchiveTask`），
**默认停用**（`status = 0`）—— 归档会删主库数据，确认对象存储可用后再手动启用。

### 1.12 `cccms:log-verify` — 校验审计日志链（定位篡改 / 删除）

```bash
php webman cccms:log-verify                      # 从校验起点校验到链尾
php webman cccms:log-verify --from=1000          # 只校验 id >= 1000
php webman cccms:log-verify --from=1000 --to=2000
```

`sys_log` 每行存 `prev_hash`（上一条的 `row_hash`）与 `row_hash`（本行内容哈希），
形成一条只增链；链尾与校验起点存在单行表 `sys_log_chain`。本命令按 id 顺序重算并比对：

| 现象 | 判定 | 说明 |
|------|------|------|
| 改过某行任意字段 | `content_modified` | 重算哈希与库中 `row_hash` 不一致，**直接报出该行 id** |
| 删掉链中间的行 | `link_broken` | 后一行的 `prev_hash` 接不上前一行 |
| 删掉 / 替换链尾 | `tail_stale` | 只有比对链状态表才能发现（只看 `max(id)` 看不出来） |
| 链状态行被整行删掉 | `chain_missing` | 链上已有记录却没有状态行 |

- **不报为问题的情形**：未入链的历史行（`row_hash` 为空，含本能力上线前的数据与降级写入）
  只统计条数；校验起点那行的 `prev_hash` 不校验（它的前驱可能已被合法归档 / 清理）；
  循环中间的**合法删除**（管理员删日志）无法与篡改区分，因此如实报出，由人工结合操作记录判断。
- 退出码：发现断点 → `1`；链自洽 → `0`（可直接接进巡检 / 告警脚本）。
- 前置：`php webman cccms:db-upgrade`（补 `prev_hash` / `row_hash` 列与 `sys_log_chain` 表）。
  未升级时命令会明确提示，而不是刷一堆「未纳入链」。

> 归档出去的 JSONL 里每行也带着 `prev_hash` / `row_hash`，配合同批的 `.chain.json` 可离线
> 验证「段内链接连续」；但归档内容经过二次脱敏，**无法**用归档文件重算行哈希 ——
> 行哈希的正确性由归档时的 `broken_at` 固化下来。

### 1.13 `cccms:plugin-create` — 生成业务插件骨架并登记菜单

```bash
php webman cccms:plugin-create shop                 # 未传 --type 时交互式选择前台 / 后台
php webman cccms:plugin-create shop \
  --type=backend --title=商城 --module=goods --module-title=商品
php webman cccms:plugin-create site --type=frontend # 公开前台插件（不产菜单）
php webman cccms:plugin-create shop --no-sync       # 只生成文件，不自动纳管
php webman cccms:plugin-create shop --force         # 目录已存在时覆盖同名文件
php webman cccms:plugin-create shop -n              # -n / --no-interaction 跳过交互
```

- 生成的**最小可运行文件集**（相对项目根）：四个 config（`app / exception / middleware / route`）、
  示例 `{模块}Controller`（后台额外带 `{模块}Logic`）与 `db/menu.php`（仅后台）。
- **交互式询问「前台 / 后台」**：
  - **后台** = 需登录 + 权限：中间件挂 `CheckLogin / CheckAuth` 等，生成 `db/menu.php`，
    命令结束**自动执行** `cccms:menu-sync` + `cccms:perm-scan` 完成纳管；
  - **前台** = 公开：只挂 `Cors`、不产 `db/menu.php`，`perm-scan` 也不会扫描它。
  - 未传 `--type` 且未加 `--no-interaction` 时才会提问；脚本 / CI 请显式传 `--type` 或 `-n`，避免等待输入。
- 插件名限定**小写字母开头**、仅含 `[a-z0-9_]`；`cccms` / `index` 为内置插件不可占用；
  目标目录已存在时默认拒绝，加 `--force` 才覆盖同名文件。
- 数据库不可用时，菜单 / 权限同步**降级为提示**（骨架文件已落盘），稍后手动补跑两条命令即可。
- 生成后仍需手工补：前端页面 `frontend/src/pages/{插件}/{模块}/index.vue`（`defineOptions` 的 `name`
  必须等于菜单 slug）、接口封装 `frontend/src/api/...`、以及表结构 `db/schema.sql`（执行 `cccms:db-upgrade`）。
  表级 CRUD（Model / Logic / Controller / 路由 / 页面）继续用后台「代码生成器」产出。
- 退出码：插件名 / 类型非法或目录已存在 → `1`；正常 → `0`。

### 1.14 `cccms:openapi-check` — 校验路由 / 权限注解 / 接口文档一致

```bash
php webman cccms:openapi-check          # 表格 + 分组统计 + 错误清单
php webman cccms:openapi-check --stat   # 只打统计（不渲染分组表，日志友好）
php webman cccms:openapi-check --json   # JSON 输出（CI 解析用）
```

- **和 §1.1 的区别**：`perm-scan` 只扫控制器注解，**不读路由表**；本命令读运行时
  `Route::getRoutes()`（含 `config/route/*.php` 的动态加载），因此能发现「有注解、有方法、
  但没挂路由」的死接口 —— 在 `Route::disableDefaultRoute()` 下这种接口**根本不存在**。
- 校验 4 类结构性问题：路由指向的方法不存在；声明了 `#[Permission]` 却无路由可达；
  非 `#[NoLogin]` 且无任何鉴权属性（fail-closed）；slug 格式非法或被多个动作占用。
- fail-closed 口径与 `PermScanner::pluginScanners()` 一致：**无 `db/menu.php` 的插件**（如公开前台
  `plugin/index`）豁免，避免两套标准打架。
- 输出里「整包透传」的计数指 `$request->get()` 无参、参数无法静态枚举的接口数量，属正常现象。
- 退出码：有不一致 → `1`；全部通过 → `0`（可直接作 CI 门禁）。

### 1.15 `cccms:orphan-check` — 孤儿数据体检

```bash
php webman cccms:orphan-check            # 只报告
php webman cccms:orphan-check --strict   # 发现孤儿数据时返回非 0（定时任务 / CI 用）
```

- 全库没有物理外键（关联靠应用层维护），本命令检查 17 组关联：用户↔角色/岗位/部门、
  角色↔权限节点、菜单/分类的父节点、字典数据↔类型、通知↔目标/已读，
  以及数据权限规则的三个绑定维度（`0` 表示「不绑定」，不算孤儿）。
- **判定口径**：父行**完全不存在**才算孤儿。父行在回收站里（软删）不算 —— 恢复父行后子行还要能用。
- 只报告不改数据：删不删要人判断（`role_node.node` 指向已删菜单时，先 `menu-sync` + `perm-scan` 更合适）。
- 退出码：`--strict` 且有孤儿数据 → `1`；其余 → `0`。

---

## 二、后台维护入口

侧边栏用户卡片的「**系统刷新**」下拉（权限节点 `cccms:config:refresh`）与 CLI **共用同一份实现**：

| 范围 | 行为 |
|------|------|
| `menu` | `MenuSyncer::syncAll()` |
| `perm` | `PermScanner` 扫描 + 校验（有错整批不写库） |
| `schema` | 执行各插件 `db/schema.sql` |
| `cache` | `SysConfig::flush()` + `PermissionMeta::flush()` |
| `all` | 以上全部 |

接口：`POST /system/refresh`。同步完前端会自动重挂动态路由并刷新当前用户权限。

---
