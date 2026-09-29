/**
 * 全局水印的变量定义与渲染。
 *
 * 供 `components/Watermark.vue`（渲染）与「配置管理 → 水印」（变量标签）共用，避免两处口径漂移。
 *
 * 变量**同时支持中英文两种写法**：中文界面点标签插入 `{用户名}`，英文界面插入 `{username}`；
 * 渲染时两种写法都识别 —— 这样切换界面语言后，已保存的水印内容不会失效。
 */

export interface WatermarkVar {
  /** 变量英文名 */
  en: string
  /** 变量中文名 */
  zh: string
}

/** 内置变量（数组顺序 = 「配置管理」里标签的展示顺序） */
export const WATERMARK_VARS: WatermarkVar[] = [
  { en: 'username', zh: '用户名' },
  { en: 'user_id', zh: '用户ID' },
  { en: 'time', zh: '时间' },
  { en: 'newline', zh: '换行' },
]

/** 点标签时插入的占位符：按当前界面语言取中/英写法 */
export function watermarkVarToken(v: WatermarkVar, locale: string): string {
  return `{${locale === 'zh-CN' ? v.zh : v.en}}`
}

/** 渲染水印需要的变量值（由调用方按当前登录用户提供） */
export interface WatermarkVarValues {
  /** 用户名（昵称优先，回落账号） */
  username: string
  /** 用户 ID */
  userId: string
  /** 当前时间的展示文本 */
  time: string
}

/**
 * 把水印内容模板渲染成纯文本。
 *
 * - 中英文写法都识别（英文大小写不敏感）：`{用户名}` / `{username}`、`{用户ID}` / `{user_id}`、
 *   `{时间}` / `{time}`、`{换行}` / `{newline}`；
 * - 字面量 `\n` 同样换算成换行（用户直接回车换行本就能保留）。
 */
export function renderWatermarkText(content: string, vars: WatermarkVarValues): string {
  return (content || '')
    .replace(/\\n/g, '\n')
    .replace(/\{(username|用户名)\}/gi, vars.username)
    .replace(/\{(user_?id|用户id)\}/gi, vars.userId)
    .replace(/\{(time|时间|日期)\}/gi, vars.time)
    .replace(/\{(newline|br|换行)\}/gi, '\n')
}
