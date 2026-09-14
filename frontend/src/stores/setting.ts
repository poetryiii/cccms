import { defineStore } from 'pinia'
import { computed, reactive, watch } from 'vue'
import { applyTheme, resolveMode, type ThemeConfig, type ThemeMode } from '@/utils/theme'
import type { AppUiDefaults } from '@/api/config'

const STORAGE_KEY = 'cccms_theme'

/** 兜底默认主题 */
export const DEFAULT_THEME: ThemeConfig = {
  mode: 'light',
  primary: '#2b6cff',
  radius: 10,
  containerWidth: 0,
}

function loadConfig(): ThemeConfig {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) {
      return { ...DEFAULT_THEME }
    }
    return { ...DEFAULT_THEME, ...(JSON.parse(raw) as Partial<ThemeConfig>) }
  } catch {
    return { ...DEFAULT_THEME }
  }
}

function hasLocalPref(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) !== null
  } catch {
    return false
  }
}

function normalizeMode(mode: string): ThemeMode {
  return mode === 'dark' || mode === 'auto' ? mode : 'light'
}

let systemWatched = false
/** 应用后台默认值时不写本地存储：只有用户自己改过才算「偏好」 */
let suppressPersist = false

export const useSettingStore = defineStore('setting', () => {
  const theme = reactive<ThemeConfig>(loadConfig())

  /** 当前是否暗色（auto 时按系统偏好实时判断） */
  const isDark = computed(() => resolveMode(theme.mode) === 'dark')

  /** 本机是否存过用户自己的主题偏好 */
  const hasOwnPref = computed(() => hasLocalPref())

  function apply(): void {
    applyTheme(theme)
  }

  function persist(): void {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(theme))
    } catch {
      // localStorage 不可用时忽略（隐私模式等）
    }
  }

  function setMode(mode: ThemeMode): void {
    theme.mode = mode
  }

  /** 在亮色 / 暗色之间切换（auto 视为当前实际效果的反面） */
  function toggleDark(): void {
    theme.mode = isDark.value ? 'light' : 'dark'
  }

  function setPrimary(color: string): void {
    theme.primary = color
  }

  function setRadius(radius: number): void {
    theme.radius = radius
  }

  function setContainerWidth(width: number): void {
    theme.containerWidth = width
  }

  /**
   * 应用后台下发的默认主题（sys_config 的 ui.*）。
   * 仅在本机没有用户偏好时生效，避免覆盖用户自己的选择。
   */
  function applySystemDefaults(ui: AppUiDefaults): void {
    if (hasLocalPref()) {
      return
    }
    suppressPersist = true
    theme.mode = normalizeMode(ui.theme_mode ?? 'light')
    if (ui.theme_primary) {
      theme.primary = ui.theme_primary
    }
    theme.containerWidth = Number(ui.container_width) || 0
    suppressPersist = false
    apply()
  }

  /** 清除本机偏好，回到后台下发的默认值 */
  function resetToSystem(ui: AppUiDefaults): void {
    try {
      localStorage.removeItem(STORAGE_KEY)
    } catch {
      // 忽略
    }
    applySystemDefaults(ui)
  }

  /** 应用主题并挂上系统偏好监听（幂等，可重复调用） */
  function init(): void {
    apply()
    if (systemWatched) {
      return
    }
    systemWatched = true
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (theme.mode === 'auto') {
        apply()
      }
    })
  }

  watch(
    theme,
    () => {
      apply()
      if (!suppressPersist) {
        persist()
      }
    },
    { deep: true },
  )

  return {
    theme,
    isDark,
    hasOwnPref,
    init,
    apply,
    setMode,
    toggleDark,
    setPrimary,
    setRadius,
    setContainerWidth,
    applySystemDefaults,
    resetToSystem,
  }
})
