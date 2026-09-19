/**
 * 富文本 HTML 的**渲染侧净化**。
 *
 * 通过富文本编辑器录入的正文存库的是 HTML，凡是把它渲染到页面上的地方都必须先过这里：
 * 否则一段 `<img src=x onerror=...>` 就能拿到登录态。后端保存时也会做一次白名单清洗
 * （见 `NoticeLogic::sanitizeContent`），前端这一次是防线里更靠近渲染的那一层，
 * 因为库里可能还存在历史数据、或绕过接口直接写库的内容。
 *
 * 用 DOMPurify 而不是手写正则：正则清洗 HTML 挡不住畸形标签、
 * 命名空间混淆与 mXSS 这类绕过手法，DOMPurify 是唯一被长期公开审计的前端净化库。
 */
import DOMPurify from 'dompurify'

/** 允许保留的标签（与后端保存侧白名单保持一致，见 NoticeLogic） */
const ALLOWED_TAGS = [
  'p',
  'br',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'del',
  'strike',
  'h1',
  'h2',
  'h3',
  'h4',
  'ul',
  'ol',
  'li',
  'blockquote',
  'code',
  'pre',
  'a',
  'img',
  'hr',
  'span',
]

/** 允许保留的属性：只留链接与图片必需的，其余（class / style / 事件）一律丢掉 */
const ALLOWED_ATTR = ['href', 'target', 'rel', 'src', 'alt', 'title']

/**
 * 净化富文本 HTML。
 *
 * DOMPurify 默认已拦截 `script` / `iframe` / `on*` 事件属性与 `javascript:` 协议，
 * 这里再显式声明一遍白名单，避免依赖默认值的隐式行为。
 */
export function sanitizeHtml(html: string): string {
  if (!html) {
    return ''
  }
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    // 不允许 data-* 透传（避免把编辑器内部标记带到业务页面里）
    ALLOW_DATA_ATTR: false,
    FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input'],
  })
}

/** 富文本是否为空：编辑器会把空文档序列化成 `<p></p>`，判空不能只看字符串长度 */
export function isBlankHtml(html: string): boolean {
  if (!html) {
    return true
  }
  const text = html
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ')
    .trim()
  // 只有图片/分隔线时也算有内容
  return text === '' && !/<(img|hr)\b/i.test(html)
}

/** 归一化：空内容统一成 `''`，避免把 `<p></p>` 这类空壳存进库 */
export function normalizeHtml(html: string): string {
  return isBlankHtml(html) ? '' : html.trim()
}
