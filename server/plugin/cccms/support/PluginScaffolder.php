<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 业务插件脚手架（P2-18）。
 *
 * 解决的问题：新建业务插件要手工复制 `plugin/index` 骨架、逐个改命名空间、再自己写
 * `db/menu.php`，极易漏项（漏了 `db/menu.php` 插件就不被纳管，漏了注解 `perm-scan --check` 就挂）。
 * 这里把「一个可运行插件」的**最小文件集**固化成模板，由 `cccms:plugin-create` 落盘。
 *
 * 与代码生成器的分工：生成器按**库表**产出 CRUD 六件套（Model/Logic/Controller/路由/前端页），
 * 本脚手架产出的是**插件容器本身**（四个 config + 示例 Controller + 菜单声明）。
 * 先有插件，才能用生成器往插件里加模块。
 *
 * 判定「业务插件」的唯一口径是 `plugin/{插件}/db/menu.php`（见 `MenuSyncer` / `PermScanner` /
 * `DataScopeChecker`），所以后台插件的菜单声明是**必须**产物，不是可选项。
 */
final class PluginScaffolder
{
    /** 插件类型：后台（需登录 + 权限）/ 前台（公开） */
    public const TYPE_BACKEND  = 'backend';
    public const TYPE_FRONTEND = 'frontend';

    /** @var array<int,string> */
    public const TYPES = [self::TYPE_BACKEND, self::TYPE_FRONTEND];

    /** 内置插件名，禁止被脚手架覆盖 */
    private const RESERVED = ['cccms', 'index'];

    /**
     * 校验并规范化插件名。
     *
     * 约束到小写字母开头，是因为插件名会直接参与命名空间、权限节点 slug 与目录名，
     * 放开大小写 / 连字符会立刻踩到 `PermScanner::SLUG_PATTERN`（只认 `[a-z0-9_]`）。
     */
    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new ApiException('插件名非法：需以小写字母开头，仅含小写字母 / 数字 / 下划线（如 shop）', 422);
        }
        if (in_array($name, self::RESERVED, true)) {
            throw new ApiException("插件名 '{$name}' 是内置插件，请换一个", 422);
        }

        return $name;
    }

    /**
     * 生成文件清单（不落盘），供命令预览与单元测试断言。
     *
     * @param  array<string,mixed> $config name / title / module / module_title / type
     * @return array<string,string> 相对**项目根**的路径 => 文件内容（如 `server/plugin/shop/config/app.php`）
     */
    public static function plan(array $config): array
    {
        $ctx   = self::context($config);
        $vars  = self::vars($ctx);
        $base  = $ctx['prefix'];
        $M     = $ctx['Module'];

        $files = [
            "{$base}/config/app.php"       => strtr(self::tplApp(), $vars),
            "{$base}/config/exception.php" => strtr(self::tplException($ctx['type']), $vars),
            "{$base}/config/middleware.php" => strtr(self::tplMiddleware($ctx['type']), $vars),
            "{$base}/config/route.php"     => strtr(self::tplRoute($ctx['type']), $vars),
            "{$base}/app/controller/{$M}Controller.php" => strtr(self::tplController($ctx['type']), $vars),
        ];

        // 后台插件才产菜单与 Logic：前台插件没有 db/menu.php，本就不参与权限体系。
        if ($ctx['type'] === self::TYPE_BACKEND) {
            $files["{$base}/app/logic/{$M}Logic.php"] = strtr(self::tplLogic(), $vars);
            $files["{$base}/db/menu.php"]             = strtr(self::tplMenu(), $vars);
        }

        return $files;
    }

    /**
     * 落盘。
     *
     * @param  array<string,mixed> $config 同 plan()，另支持 force（覆盖已存在目录）
     * @param  string|null         $root   项目根（默认 server 的上一级；测试可指定临时目录）
     * @return array<int,string> 已写入的相对路径
     */
    public static function create(array $config, ?string $root = null): array
    {
        $root = rtrim(str_replace('\\', '/', $root ?? dirname(rtrim(base_path(), '/\\'))), '/');
        $ctx  = self::context($config);

        $pluginDir = $root . '/' . $ctx['prefix'];
        if (is_dir($pluginDir) && empty($config['force'])) {
            throw new ApiException("插件目录已存在：{$ctx['prefix']}（如需覆盖同名文件，请加 --force）", 422);
        }

        $written = [];
        foreach (self::plan($config) as $rel => $content) {
            $full = $root . '/' . $rel;
            $dir  = dirname($full);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ApiException("目录创建失败：{$dir}", 500);
            }
            if (file_put_contents($full, $content) === false) {
                throw new ApiException("文件写入失败：{$rel}", 500);
            }
            $written[] = $rel;
        }

        return $written;
    }

    /**
     * @param  array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function context(array $config): array
    {
        $name   = self::normalizeName((string)($config['name'] ?? ''));
        $type   = self::normalizeType((string)($config['type'] ?? self::TYPE_BACKEND));
        $module = self::normalizeModule((string)($config['module'] ?? 'demo'));

        $title       = trim((string)($config['title'] ?? '')) ?: ucfirst(str_replace('_', ' ', $name));
        $moduleTitle = trim((string)($config['module_title'] ?? '')) ?: ($module === 'demo' ? '示例' : ucfirst($module));

        return [
            'name'         => $name,
            'type'         => $type,
            'title'        => $title,
            'module'       => $module,
            'Module'       => self::pascal($module),
            'module_title' => $moduleTitle,
            'prefix'       => "server/plugin/{$name}",
            'path'         => "/{$name}",
            'module_path'  => "/{$name}/{$module}",
            'menu_slug'    => "{$name}:{$module}",
            'component'    => "{$name}/{$module}/index",
        ];
    }

    private static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            throw new ApiException("插件类型非法：'{$type}'（可选 backend / frontend）", 422);
        }

        return $type;
    }

    private static function normalizeModule(string $module): string
    {
        $module = strtolower(trim($module));
        if ($module === '' || preg_match('/^[a-z][a-z0-9_]*$/', $module) !== 1) {
            throw new ApiException('模块名非法：需以小写字母开头，仅含小写字母 / 数字 / 下划线（如 goods）', 422);
        }

        return $module;
    }

    /** @param array<string,mixed> $ctx */
    private static function vars(array $ctx): array
    {
        return [
            '{{plugin}}'       => $ctx['name'],
            '{{title}}'        => $ctx['title'],
            '{{module}}'       => $ctx['module'],
            '{{Module}}'       => $ctx['Module'],
            '{{module_title}}' => $ctx['module_title'],
            '{{path}}'         => $ctx['path'],
            '{{module_path}}'  => $ctx['module_path'],
            '{{menu_slug}}'    => $ctx['menu_slug'],
            '{{component}}'    => $ctx['component'],
        ];
    }

    private static function pascal(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }

    // ------------------------------------------------------------------
    // 模板（占位符由 strtr 替换；用 nowdoc 以免生成代码里的 $ 被当成插值）
    // ------------------------------------------------------------------

    private static function tplApp(): string
    {
        return <<<'PHP'
        <?php

        return [
            'enable' => true,
        ];

        PHP;
    }

    private static function tplException(string $type): string
    {
        if ($type === self::TYPE_BACKEND) {
            return <<<'PHP'
            <?php

            use plugin\cccms\support\ExceptionHandler;

            /*
             * 后台业务插件：沿用 cccms 的统一异常渲染（Result JSON 结构），
             * 使前端拿到的错误体与框架其它接口一致。
             */
            return [
                '' => ExceptionHandler::class,
            ];

            PHP;
        }

        return <<<'PHP'
        <?php

        use support\exception\Handler;

        /*
         * 公开前台插件：保持 webman 默认异常渲染。
         * 根 config/exception.php 的 Result 化渲染是给后台业务插件用的，
         * 公开站点不需要 JSON 化的错误体，故这里显式声明沿用默认处理器。
         */
        return [
            '' => Handler::class,
        ];

        PHP;
    }

    private static function tplMiddleware(string $type): string
    {
        if ($type === self::TYPE_BACKEND) {
            return <<<'PHP'
            <?php

            use plugin\cccms\app\middleware\CheckAuth;
            use plugin\cccms\app\middleware\CheckLogin;
            use plugin\cccms\app\middleware\Cors;
            use plugin\cccms\app\middleware\Maintenance;
            use plugin\cccms\app\middleware\OperationLog;
            use plugin\cccms\app\middleware\RateLimit;
            use plugin\cccms\app\middleware\ResponseEncode;
            use plugin\cccms\app\middleware\Xss;

            /*
             * 后台业务插件中间件：与 plugin/cccms 同一套鉴权链。
             *
             * 关键点：中间件**按插件作用域**生效，只有在这里挂上 CheckLogin / CheckAuth，
             * 本插件的路由才会被纳入登录与权限校验；不需要鉴权的接口可另建前台插件。
             * 顺序不能随意调换（见 plugin/cccms/config/middleware.php 的注释）。
             */
            return [
                '' => [
                    Cors::class,
                    Xss::class,
                    CheckLogin::class,
                    RateLimit::class,
                    Maintenance::class,
                    CheckAuth::class,
                    ResponseEncode::class,
                    OperationLog::class,
                ],
            ];

            PHP;
        }

        return <<<'PHP'
        <?php

        use plugin\cccms\app\middleware\Cors;

        /*
         * 公开前台插件中间件：只挂 Cors，路由天然公开。
         * 无需给每个方法写 #[NoLogin]（写了也不会被执行，因为鉴权中间件没有挂上）。
         */
        return [
            '' => [
                Cors::class,
            ],
        ];

        PHP;
    }

    private static function tplRoute(string $type): string
    {
        if ($type === self::TYPE_BACKEND) {
            return <<<'PHP'
            <?php

            use plugin\{{plugin}}\app\controller\{{Module}}Controller;
            use Webman\Route;

            // 禁用默认路由（/controller/action 自动路由），只允许显式路由
            Route::disableDefaultRoute();

            // ---- {{module_title}} ----
            Route::get('{{module_path}}', [{{Module}}Controller::class, 'index']);
            Route::get('{{module_path}}/read', [{{Module}}Controller::class, 'read']);
            Route::post('{{module_path}}/save', [{{Module}}Controller::class, 'save']);
            Route::post('{{module_path}}/delete', [{{Module}}Controller::class, 'delete']);

            // ---- 代码生成器产出的模块路由（config/route/*.php） ----
            foreach (glob(__DIR__ . '/route/*.php') ?: [] as $__routeFile) {
                require $__routeFile;
            }

            PHP;
        }

        return <<<'PHP'
        <?php

        use plugin\{{plugin}}\app\controller\{{Module}}Controller;
        use Webman\Route;

        Route::disableDefaultRoute();

        // ---- 前台公开接口 ----
        Route::get('{{path}}/ping', [{{Module}}Controller::class, 'ping']);
        Route::get('{{module_path}}', [{{Module}}Controller::class, 'index']);

        foreach (glob(__DIR__ . '/route/*.php') ?: [] as $__routeFile) {
            require $__routeFile;
        }

        PHP;
    }

    private static function tplController(string $type): string
    {
        if ($type === self::TYPE_BACKEND) {
            return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace plugin\{{plugin}}\app\controller;

            use plugin\cccms\basic\BaseController;
            use plugin\cccms\support\attribute\Permission;
            use plugin\cccms\support\attribute\Restrict;
            use plugin\{{plugin}}\app\logic\{{Module}}Logic;
            use Webman\Http\Request;
            use Webman\Http\Response;

            /**
             * {{module_title}} 控制器（{{title}}插件示例）。
             *
             * 由 `cccms:plugin-create` 生成，开箱可运行；接入真实业务时替换 Logic 实现即可。
             * 注意：控制器**不得直连数据库**（Db::name / Db::table / Db::query），查询一律下沉到 Logic，
             * 否则会被 `cccms:data-scope-check` 拦下（在控制器里查库等于绕过数据权限）。
             *
             * 每个 public 方法都必须声明 #[Permission] / #[NoAuth] / #[NoLogin]，
             * 否则 `cccms:perm-scan --check` 会以 fail-closed 报错。
             */
            class {{Module}}Controller extends BaseController
            {
                #[Permission(slug: '{{menu_slug}}:index', title: '{{module_title}}列表')]
                public function index(Request $request): Response
                {
                    return $this->ok({{Module}}Logic::paginate($request->get()));
                }

                #[Permission(slug: '{{menu_slug}}:read', title: '{{module_title}}详情')]
                public function read(Request $request): Response
                {
                    return $this->ok({{Module}}Logic::read((int)$request->get('id', 0)));
                }

                #[Permission(slug: '{{menu_slug}}:save', title: '新增{{module_title}}')]
                #[Restrict(methods: ['POST'])]
                public function save(Request $request): Response
                {
                    return $this->ok(['id' => {{Module}}Logic::save($request->post())]);
                }

                #[Permission(slug: '{{menu_slug}}:delete', title: '删除{{module_title}}')]
                #[Restrict(methods: ['POST'])]
                public function delete(Request $request): Response
                {
                    {{Module}}Logic::delete((int)$request->post('id', 0));

                    return $this->ok();
                }
            }

            PHP;
        }

        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace plugin\{{plugin}}\app\controller;

        use plugin\cccms\basic\BaseController;
        use Webman\Http\Request;
        use Webman\Http\Response;

        /**
         * {{title}} 前台（公开）接口。
         *
         * 本插件只挂 Cors 中间件，所有方法默认公开；若某个接口需要登录 / 权限，
         * 请改用 `cccms:plugin-create {{plugin}} --type=backend` 生成的后台骨架，
         * 或在 config/middleware.php 里挂载 CheckLogin / CheckAuth 并补上注解。
         */
        class {{Module}}Controller extends BaseController
        {
            /** 健康检查 */
            public function ping(Request $request): Response
            {
                return $this->ok(['app' => '{{plugin}}', 'time' => date('Y-m-d H:i:s')]);
            }

            /** 示例首页数据 */
            public function index(Request $request): Response
            {
                return $this->ok([
                    'title'  => '{{title}}',
                    'notice' => '前台插件骨架，按需在此扩展业务',
                ]);
            }
        }

        PHP;
    }

    private static function tplLogic(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace plugin\{{plugin}}\app\logic;

        /**
         * {{module_title}} 逻辑层（{{title}}插件示例）。
         *
         * 这里刻意不碰数据库，保证骨架生成后即可运行。接入真实表时：
         *   1. 在 app/model 下建模型：表参与数据权限就声明语义，不参与则显式 `$dataScope = false`；
         *   2. 查询一律走模型（数据权限由模型全局作用域提供），不要在 Controller 里直连数据库；
         *   3. 表结构写在 db/schema.sql（用 `CREATE TABLE IF NOT EXISTS`），由 cccms:db-upgrade 执行。
         */
        final class {{Module}}Logic
        {
            /**
             * 示例分页查询。
             *
             * @param  array<string,mixed> $params
             * @return array{list:array<int,array>,total:int,page:int,pageSize:int}
             */
            public static function paginate(array $params): array
            {
                return [
                    'list'     => [self::demoRow(1)],
                    'total'    => 1,
                    'page'     => max(1, (int)($params['page'] ?? 1)),
                    'pageSize' => max(1, (int)($params['pageSize'] ?? 10)),
                ];
            }

            /** @return array<string,mixed> */
            public static function read(int $id): array
            {
                return self::demoRow($id);
            }

            /** @param array<string,mixed> $data */
            public static function save(array $data): int
            {
                // TODO: 接入模型写入，返回主键
                return (int)($data['id'] ?? 0);
            }

            public static function delete(int $id): void
            {
                // TODO: 接入模型删除（含软删 / 回收站）
            }

            /** @return array<string,mixed> */
            private static function demoRow(int $id): array
            {
                return [
                    'id'          => $id,
                    'name'        => '{{module_title}}示例数据',
                    'create_time' => date('Y-m-d H:i:s'),
                ];
            }
        }

        PHP;
    }

    private static function tplMenu(): string
    {
        return <<<'PHP'
        <?php

        /**
         * 菜单 / 目录声明（type=1 目录、type=2 菜单）。
         * 按钮（type=3）由控制器 #[Permission] 注解经 cccms:perm-scan 自动生成，此处不声明。
         *
         * 命名约定：{插件}:{模块}:{动作}
         *   目录 slug = {{plugin}}
         *   菜单 slug = {{menu_slug}}
         *   按钮 slug = {{menu_slug}}:{动作}
         *   前端页面  = pages/{{component}}.vue（defineOptions 的 name 必须等于菜单 slug）
         *
         * 同步：php webman cccms:menu-sync
         * 删除：补一条 ['slug' => 'xxx', 'remove' => true]，menu-sync 会连带删除子树与角色授权。
         */
        return [
            [
                'slug'      => '{{plugin}}',
                'title'     => '{{title}}',
                'type'      => 1,
                'icon'      => 'icon-apps',
                'path'      => '{{path}}',
                'component' => '',
                'sort'      => 99,
                'children'  => [
                    [
                        'slug'      => '{{menu_slug}}',
                        'title'     => '{{module_title}}',
                        'type'      => 2,
                        'path'      => '{{module_path}}',
                        'component' => '{{component}}',
                        'icon'      => 'icon-apps',
                        'sort'      => 1,
                    ],
                ],
            ],
        ];

        PHP;
    }
}
