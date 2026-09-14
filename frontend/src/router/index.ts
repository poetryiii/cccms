import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import { getToken } from '@/utils/auth'
import { ROUTE_PROGRESS, progressDone, progressStart } from '@/utils/progress'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { useMenuStore } from '@/stores/menu'
import { useWorktabStore } from '@/stores/worktab'
import type { MenuNode } from '@/api/types'

// 进度条配置与并发计数统一在 @/utils/progress（与 HTTP 请求、附件上传共用一条进度条）

const APP_TITLE = 'CCCMS 管理系统'

// 页面组件自动注册：菜单 component 字段（如 cccms/user/index）→ /src/pages/cccms/user/index.vue
const pageModules = import.meta.glob('/src/pages/**/*.vue')

function resolveComponent(component: string) {
  return pageModules[`/src/pages/${component}.vue`]
}

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/cccms/login/index.vue'),
    meta: { public: true, title: '登录' },
  },
  {
    path: '/',
    name: 'layout',
    component: () => import('@/layouts/BasicLayout.vue'),
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/pages/cccms/dashboard/index.vue'),
        // 标题与菜单里的「工作台」保持一致（面包屑 / 标签页 / document.title 都取这里）
        meta: { title: '工作台', icon: 'icon-home' },
      },
      {
        path: 'profile',
        name: 'profile',
        component: () => import('@/pages/cccms/profile/index.vue'),
        // 个人中心是静态路由（不写进 db/menu.php），所以不会出现在左侧菜单里；
        // 入口在右上角用户下拉。
        meta: { title: '个人中心', icon: 'icon-user' },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'notFound',
    component: () => import('@/pages/cccms/error/404.vue'),
    // 注意：这里**不能**标 public。
    // 刷新页面时首帧一定先落到这个兜底路由（动态路由尚未注册），
    // 若守卫因为它 public 就提前返回，就会跳过动态路由注册，把「刷新」误判成 404。
    meta: { title: '页面不存在' },
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

let dynamicAdded = false
/** router.addRoute 返回的移除函数，登出时用来清理，避免换账号后残留旧权限路由 */
let removeDynamicRoutes: Array<() => void> = []

function buildRoutes(menus: MenuNode[]): RouteRecordRaw[] {
  const out: RouteRecordRaw[] = []
  const walk = (list: MenuNode[]) => {
    for (const m of list) {
      // 只有 type=2（菜单）且声明了 component 的才注册为路由；目录本身不产生路由
      if (m.type === 2 && m.component) {
        const comp = resolveComponent(m.component)
        if (comp) {
          out.push({
            path: (m.path || '').replace(/^\//, ''),
            name: m.node || `menu-${m.id}`,
            component: comp,
            meta: { title: m.title, icon: m.icon, node: m.node },
          })
        }
      }
      if (m.children?.length) {
        walk(m.children)
      }
    }
  }
  walk(menus)
  return out
}

function addDynamicRoutes(menus: MenuNode[]): void {
  removeDynamicRoutes = buildRoutes(menus).map((route) => router.addRoute('layout', route))
  dynamicAdded = true
}

/** 登出 / 换账号时调用：清掉动态路由，允许下次重新注册 */
export function resetDynamicRoutes(): void {
  removeDynamicRoutes.forEach((remove) => remove())
  removeDynamicRoutes = []
  dynamicAdded = false
}

/**
 * 重新拉取菜单与当前用户权限，并重挂动态路由。
 *
 * 后台「同步菜单 / 同步按钮节点」后调用：让侧边栏与按钮权限立刻反映新节点，
 * 不必再手动刷新页面。
 */
export async function reloadMenus(): Promise<void> {
  resetDynamicRoutes()
  const menuStore = useMenuStore()
  addDynamicRoutes(await menuStore.load())
  await useUserStore().fetchProfile()
}

router.beforeEach(async (to) => {
  // 覆盖守卫里的异步加载：拉用户信息、菜单与路由懒加载组件都算在进度条内
  progressStart(ROUTE_PROGRESS)

  // 未登录：只放行显式标记 public 的页面（登录页），其余一律去登录
  if (!getToken()) {
    return to.meta.public ? true : { path: '/login', query: { redirect: to.fullPath } }
  }

  const user = useUserStore()
  if (!user.profile) {
    try {
      await user.fetchProfile()
    } catch {
      return { path: '/login' }
    }
  }

  if (!dynamicAdded) {
    const menuStore = useMenuStore()
    try {
      addDynamicRoutes(await menuStore.load())
    } catch {
      // 菜单拉取失败不阻塞导航：标记为已注册，交给 404 兜底，避免守卫反复重入
      dynamicAdded = true
    }
    // 动态路由注册完成后，按「路径」重新导航一次。
    //
    // 注意这里**不能**写成 `{ ...to, replace: true }`：
    // 刷新时首帧匹配到的是 404 兜底路由，`to.name` 就是 'notFound'，
    // 而 vue-router 解析 location 时 `name` 优先于 `path`，
    // 结果会被再解析回兜底路由 —— 表现就是「动态路由明明注册了，刷新还是 404」。
    // 所以这里只带 path/query/hash，强制按路径重新匹配。
    return { path: to.path, query: to.query, hash: to.hash, replace: true }
  }

  return true
})

router.afterEach((to) => {
  progressDone(ROUTE_PROGRESS)
  // 站点名取自后台配置 system.name
  const appName = useAppStore().systemName || APP_TITLE
  document.title = to.meta.title ? `${String(to.meta.title)} - ${appName}` : appName
})

router.onError(() => {
  progressDone(ROUTE_PROGRESS)
})

/** 退出登录后统一清理（路由 + 标签页） */
export function resetAfterLogout(): void {
  resetDynamicRoutes()
  useWorktabStore().reset()
}

export default router
