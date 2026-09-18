import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import zhCN from './zh-CN'
import enUS from './en-US'

/** 可用语言项（label 用于语言切换入口展示） */
export interface LocaleOption {
  label: string
  value: string
}

/** 受支持的语言字面量（与语言包、Element Plus 语言映射保持一致） */
export type AppLocale = 'zh-CN' | 'en-US'

export const DEFAULT_LOCALE: AppLocale = 'zh-CN'

/** localStorage key：与主题偏好一样，语言偏好只存本机 */
export const LOCALE_STORAGE_KEY = 'cccms_locale'

export const SUPPORTED_LOCALES: LocaleOption[] = [
  { label: '简体中文', value: 'zh-CN' },
  { label: 'English', value: 'en-US' },
]

/**
 * 把任意输入归一化到受支持的语言：
 * 命中则原样返回，`zh` / `en` 这类简写按前缀匹配，其余回落到默认语言。
 */
function normalizeLocale(locale: string | null | undefined): AppLocale {
  if (!locale) {
    return DEFAULT_LOCALE
  }
  const lower = locale.trim().toLowerCase()
  if (lower === '') {
    return DEFAULT_LOCALE
  }
  const exact = SUPPORTED_LOCALES.find((item) => item.value.toLowerCase() === lower)
  if (exact) {
    return exact.value as AppLocale
  }
  const prefix = SUPPORTED_LOCALES.find((item) => item.value.toLowerCase().startsWith(lower))
  return (prefix?.value as AppLocale | undefined) ?? DEFAULT_LOCALE
}

/** 读取本机语言偏好（localStorage 不可用时回落默认语言） */
export function getLocale(): AppLocale {
  try {
    return normalizeLocale(localStorage.getItem(LOCALE_STORAGE_KEY))
  } catch {
    return DEFAULT_LOCALE
  }
}

export const i18n = createI18n({
  // Composition API 模式：locale 才是响应式 ref，切换语言无需刷新页面
  legacy: false,
  globalInjection: true,
  locale: getLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: {
    'zh-CN': zhCN,
    'en-US': enUS,
  },
})

/**
 * 当前语言的响应式镜像。
 *
 * 供非组件上下文（如 Element Plus 的 ElConfigProvider、axios 请求头）读取；
 * `i18n.global.locale` 本身也是响应式的，这里额外暴露一个 ref 是为了避免
 * 在这些场景里依赖 vue-i18n 的内部类型。
 */
export const currentLocale = ref(getLocale())

/** 非组件上下文使用的翻译函数（组件内优先用 useI18n 以自动追踪响应式） */
export function t(key: string, named?: Record<string, unknown>): string {
  const translate = i18n.global.t as unknown as (key: string, named?: Record<string, unknown>) => string
  return translate(key, named)
}

/** 本机是否存过用户自己的语言偏好 */
export function hasLocalePref(): boolean {
  try {
    return localStorage.getItem(LOCALE_STORAGE_KEY) !== null
  } catch {
    return false
  }
}

/**
 * 应用一个语言（不写本地存储）。
 *
 * 供 app store 在启动时应用「后台下发的默认语言」：只有用户没存过本机偏好时才调用，
 * 因此不需要（也不应该）写 localStorage —— 否则会把后台默认值误当成用户偏好。
 */
export function applyDefaultLocale(locale: string): void {
  const next = normalizeLocale(locale)
  i18n.global.locale.value = next
  currentLocale.value = next
  document.documentElement.setAttribute('lang', next)
}

/**
 * 切换语言：更新 i18n 实例 + 响应式镜像 + 持久化 + 同步 <html lang>。
 *
 * 即时生效：`currentLocale` 变化会驱动 ElConfigProvider 重新下发语言包，
 * 日期选择器 / 分页等 Element Plus 内置文案随之切换，无需刷新页面。
 */
export function setLocale(locale: string): void {
  applyDefaultLocale(locale)
  try {
    localStorage.setItem(LOCALE_STORAGE_KEY, currentLocale.value)
  } catch {
    // localStorage 不可用时仅本次会话生效
  }
}
