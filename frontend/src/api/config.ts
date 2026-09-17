import { http } from './request'

/** 支持的控件类型，与 sys_config.type 一一对应（password = 敏感项，后端加密存储、读取时脱敏） */
export type ConfigType = 'input' | 'switch' | 'select' | 'input-number' | 'textarea' | 'radio' | 'password'

export interface ConfigOption {
  label: string
  value: string | number | boolean
}

export interface ConfigItem {
  id: number
  /** 配置键，如 system.name */
  name: string
  title: string
  type: ConfigType
  value: string | null
  /** select / radio 的候选值，后端按 JSON 返回 */
  options: ConfigOption[] | string | null
  /** 仅 type=password 返回：敏感项是否已配置过（密文本身不下发） */
  has_value?: boolean
  group: string
  sort: number
  status: number
  remark: string
}

/* ---------- 前端初始化用的公开配置（GET /config/ui） ---------- */

export interface AppSystemConfig {
  name: string
  logo: string
  icp: string
  copyright: string
  maintenance: boolean
  notice: string
}

export interface AppUiDefaults {
  theme_mode: string
  theme_primary: string
  page_size: number
  tags_view: boolean
  container_width: number
}

export interface AppUiConfig {
  system: AppSystemConfig
  ui: AppUiDefaults
}

/** 不传 group 则返回全部配置 */
export function configList(group = '') {
  return http.get<ConfigItem[]>('/config', group ? { group } : {})
}

/** 品牌信息与 UI 默认值（无需登录） */
export function configUi() {
  return http.get<AppUiConfig>('/config/ui')
}

/** 只提交发生变更的配置项 */
export function configSave(data: Record<string, unknown>) {
  return http.post<{ updated: number }>('/config/save', data)
}
