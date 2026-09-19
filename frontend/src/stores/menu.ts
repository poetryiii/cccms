import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { userTree } from '@/api/menu'
import type { MenuNode } from '@/api/types'
import { useUserStore } from './user'

/** 快捷导航收藏的本地存储前缀（按 用户 + 租户 隔离） */
const FAVORITE_PREFIX = 'cccms_quick_nav_fav:'

/** 可跳转的扁平菜单项（快捷导航搜索 / 工作台快捷入口共用） */
export interface FlatMenu {
  title: string
  path: string
  icon: string
}

/** 只取可跳转的菜单（type=2 且 path 非空），目录与按钮不参与 */
function flatten(nodes: MenuNode[]): FlatMenu[] {
  const out: FlatMenu[] = []
  const walk = (list: MenuNode[]): void => {
    for (const node of list) {
      if (node.type === 2 && node.path) {
        out.push({ title: node.title, path: node.path, icon: node.icon })
      }
      if (node.children?.length) {
        walk(node.children)
      }
    }
  }
  walk(nodes)
  return out
}

function readFavorites(key: string): string[] {
  try {
    const raw = localStorage.getItem(key)
    const parsed: unknown = raw ? JSON.parse(raw) : null
    return Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : []
  } catch {
    // localStorage 不可用 / 脏数据时视为「无收藏」
    return []
  }
}

function writeFavorites(key: string, paths: string[]): void {
  try {
    localStorage.setItem(key, JSON.stringify(paths))
  } catch {
    // localStorage 不可用时仅本次会话生效
  }
}

export const useMenuStore = defineStore('menu', () => {
  const menus = ref<MenuNode[]>([])
  const loaded = ref(false)

  /** 可跳转菜单的扁平视图（快捷导航、工作台快捷入口） */
  const flatMenus = computed(() => flatten(menus.value))

  /** 快捷导航收藏（存菜单 path，展示时再回查标题与图标） */
  const favorites = ref<string[]>([])
  /** 当前生效的存储 key；为空表示还没绑定用户（登录前 / 已登出） */
  let favoriteKey = ''

  /** 收藏隔离到「用户 + 租户」：不同账号、不同租户的菜单集合本就不同 */
  function resolveFavoriteKey(): string {
    const user = useUserStore()
    return FAVORITE_PREFIX + `${user.profile?.id ?? 'anon'}:${user.tenantId}`
  }

  function loadFavorites(): void {
    favoriteKey = resolveFavoriteKey()
    favorites.value = readFavorites(favoriteKey)
  }

  function persistFavorites(): void {
    if (favoriteKey) {
      writeFavorites(favoriteKey, favorites.value)
    }
  }

  function isFavorite(path: string): boolean {
    return favorites.value.includes(path)
  }

  function toggleFavorite(path: string): void {
    if (!path) {
      return
    }
    // 尚未绑定存储 key（理论上不会发生：布局渲染前已登录）时先补一次
    if (!favoriteKey) {
      loadFavorites()
    }
    favorites.value = isFavorite(path) ? favorites.value.filter((item) => item !== path) : [...favorites.value, path]
    persistFavorites()
  }

  /** 收藏排序：把某项上/下移一位（越界时不动） */
  function moveFavorite(path: string, delta: number): void {
    const from = favorites.value.indexOf(path)
    const to = from + delta
    if (from < 0 || to < 0 || to >= favorites.value.length) {
      return
    }
    const next = [...favorites.value]
    next.splice(to, 0, ...next.splice(from, 1))
    favorites.value = next
    persistFavorites()
  }

  /** 收藏拖拽排序：把 from 位置的项插到 to 位置（下标越界或原地不动时忽略） */
  function reorderFavorite(from: number, to: number): void {
    const list = favorites.value
    if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) {
      return
    }
    const next = [...list]
    next.splice(to, 0, ...next.splice(from, 1))
    favorites.value = next
    persistFavorites()
  }

  /** 收藏菜单项（按收藏顺序回查标题图标，菜单里已不存在的直接跳过） */
  const favoriteMenus = computed<FlatMenu[]>(() => {
    const byPath = new Map(flatMenus.value.map((item) => [item.path, item]))
    return favorites.value.map((path) => byPath.get(path)).filter((item): item is FlatMenu => !!item)
  })

  async function load(): Promise<MenuNode[]> {
    menus.value = await userTree()
    loaded.value = true
    loadFavorites()
    return menus.value
  }

  function reset(): void {
    menus.value = []
    loaded.value = false
    favorites.value = []
    favoriteKey = ''
  }

  return {
    menus,
    loaded,
    flatMenus,
    favorites,
    favoriteMenus,
    isFavorite,
    toggleFavorite,
    moveFavorite,
    reorderFavorite,
    load,
    reset,
  }
})
