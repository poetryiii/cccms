import { defineStore } from 'pinia'
import { computed, nextTick, ref } from 'vue'

export interface TabItem {
  /** 路由完整路径，作为唯一 key */
  path: string
  /** 路由 name，同时是页面组件的 name（keep-alive include 依赖它） */
  name: string
  title: string
  icon?: string
  /** 固定标签：不可关闭，始终排在最前 */
  pinned: boolean
}

/** 首页标签：固定存在，不受权限控制 */
export const HOME_PATH = '/dashboard'

/** 刷新后重新缓存的延迟（等 keep-alive 完成裁剪） */
const REFRESH_DELAY = 60

function createHomeTab(): TabItem {
  return { path: HOME_PATH, name: 'dashboard', title: '仪表盘', icon: 'icon-home', pinned: true }
}

export const useWorktabStore = defineStore('worktab', () => {
  const tabs = ref<TabItem[]>([createHomeTab()])
  const active = ref<string>(HOME_PATH)

  /** 临时移出缓存的组件名（用于「刷新当前页」） */
  const excluded = ref<string[]>([])

  /**
   * keep-alive 的 include 名单 = 已打开标签的组件名。
   * 关闭标签即从名单移除，实例才会真正销毁。
   */
  const cached = computed<string[]>(() =>
    tabs.value.map((t) => t.name).filter((n) => !excluded.value.includes(n)),
  )

  function findIndex(path: string): number {
    return tabs.value.findIndex((t) => t.path === path)
  }

  function setActive(path: string): void {
    active.value = path
  }

  /** 打开（或激活）标签；已存在时只更新文案，保证菜单改名后能跟随 */
  function addTab(tab: Omit<TabItem, 'pinned'> & { pinned?: boolean }): void {
    const index = findIndex(tab.path)
    if (index >= 0) {
      tabs.value[index] = { ...tabs.value[index], title: tab.title, icon: tab.icon }
      return
    }
    const item: TabItem = { ...tab, pinned: tab.pinned ?? false }
    if (item.pinned) {
      tabs.value.unshift(item)
    } else {
      tabs.value.push(item)
    }
  }

  /** @returns 关闭后应跳转的路径；关闭的不是当前标签时返回 null */
  function closeTab(path: string): string | null {
    const index = findIndex(path)
    if (index < 0 || tabs.value[index].pinned) {
      return null
    }
    tabs.value.splice(index, 1)

    if (active.value !== path) {
      return null
    }
    const next = tabs.value[index] ?? tabs.value[index - 1] ?? tabs.value[0]
    return next ? next.path : HOME_PATH
  }

  /** 关闭其他（固定标签与指定标签保留） */
  function closeOthers(path: string): void {
    tabs.value = tabs.value.filter((t) => t.pinned || t.path === path)
  }

  /** 关闭左侧（固定标签不受影响） */
  function closeLeft(path: string): void {
    const index = findIndex(path)
    if (index < 0) {
      return
    }
    tabs.value = tabs.value.filter((t, i) => t.pinned || i >= index)
  }

  /** 关闭右侧 */
  function closeRight(path: string): void {
    const index = findIndex(path)
    if (index < 0) {
      return
    }
    tabs.value = tabs.value.filter((t, i) => t.pinned || i <= index)
  }

  /** 关闭全部，返回应该跳转的首页路径 */
  function closeAll(): string {
    tabs.value = tabs.value.filter((t) => t.pinned)
    return HOME_PATH
  }

  /** 固定 / 取消固定：固定项整体排到前面 */
  function togglePin(path: string): void {
    const tab = tabs.value[findIndex(path)]
    if (!tab || tab.path === HOME_PATH) {
      return
    }
    tab.pinned = !tab.pinned
    tabs.value = [
      ...tabs.value.filter((t) => t.pinned),
      ...tabs.value.filter((t) => !t.pinned),
    ]
  }

  /**
   * 刷新指定页面：先把组件名移出 include（keep-alive 会销毁缓存实例），
   * 稍后再放回，组件重新挂载即达到「刷新」效果。
   */
  function refresh(path: string): void {
    const tab = tabs.value[findIndex(path)]
    if (!tab || excluded.value.includes(tab.name)) {
      return
    }
    excluded.value = [...excluded.value, tab.name]
    window.setTimeout(() => {
      excluded.value = excluded.value.filter((n) => n !== tab.name)
    }, REFRESH_DELAY)
  }

  /** 刷新当前激活标签 */
  async function refreshActive(): Promise<void> {
    refresh(active.value)
    await nextTick()
  }

  function reset(): void {
    tabs.value = [createHomeTab()]
    active.value = HOME_PATH
    excluded.value = []
  }

  return {
    tabs,
    cached,
    active,
    setActive,
    addTab,
    closeTab,
    closeOthers,
    closeLeft,
    closeRight,
    closeAll,
    togglePin,
    refresh,
    refreshActive,
    reset,
  }
})
