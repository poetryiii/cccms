<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use ReflectionAttribute;
use ReflectionClass;

/**
 * 注解元数据解析器。
 *
 * 进程内静态缓存：Webman worker 常驻，反射每进程只做一次；
 * 部署 reload 后自动失效，无需清理外部缓存。
 */
final class PermissionMeta
{
    private const DEFAULT_META = [
        'slug'    => null,
        'title'   => null,
        'sort'    => 0,
        'group'   => null,
        'noAuth'  => false,
        'noLogin' => false,
        'methods' => [],
        'encode'  => null,
    ];

    /** @var array<string, array> */
    private static array $cache = [];

    /**
     * @return array{slug:?string,title:?string,sort:int,group:?string,noAuth:bool,noLogin:bool,methods:array,encode:?array}
     */
    public static function of(string $controller, string $action): array
    {
        return self::$cache[$controller . '@' . $action] ??= self::resolve($controller, $action);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** 注解里声明的操作名；没写就是 null（操作日志里显示「—」） */
    private static function titleOf(?ReflectionAttribute $attr): ?string
    {
        return $attr?->newInstance()->title ?: null;
    }

    private static function resolve(string $controller, string $action): array
    {
        $meta = self::DEFAULT_META;
        if ($controller === '' || $action === '' || !class_exists($controller) || !method_exists($controller, $action)) {
            return $meta;
        }

        $class  = new ReflectionClass($controller);
        $method = $class->getMethod($action);

        // NoLogin / NoAuth 不是权限点（不产生按钮节点），但可以带一个操作名：
        // 操作日志靠它把 `/auth/login` 显示成「登录」，否则只能显示「—」。
        $noLogin = $method->getAttributes(NoLogin::class)[0]
                ?? $class->getAttributes(NoLogin::class)[0] ?? null;
        $noAuth  = $method->getAttributes(NoAuth::class)[0] ?? null;

        if ($noLogin !== null) {
            $meta['noLogin'] = true;
            $meta['title']   = self::titleOf($noLogin);
        } elseif ($noAuth !== null) {
            $meta['noAuth'] = true;
            $meta['title']  = self::titleOf($noAuth);
        } elseif (($perms = $method->getAttributes(Permission::class)) !== []) {
            $p = $perms[0]->newInstance();
            $meta['slug']  = $p->slug;
            $meta['title'] = $p->title;
            $meta['sort']  = $p->sort;
            $meta['group'] = $p->group;
        }

        // Restrict：方法级优先，类级兜底；对 NoLogin / NoAuth / Permission 均生效
        $attr = $method->getAttributes(Restrict::class)[0]
             ?? $class->getAttributes(Restrict::class)[0] ?? null;
        if ($attr !== null) {
            $r = $attr->newInstance();
            $meta['methods'] = $r->methods;
            $meta['encode']  = $r->encode;
        }

        return $meta;
    }
}
