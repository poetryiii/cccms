import { describe, expect, it } from 'vitest'
import { isBlankHtml, normalizeHtml, sanitizeHtml } from '@/utils/richText'

describe('sanitizeHtml', () => {
  it('保留白名单标签与属性', () => {
    const html = '<p>正文<strong>加粗</strong></p><h2>标题</h2><a href="https://a.com" target="_blank">链接</a>'
    expect(sanitizeHtml(html)).toBe(html)
  })

  it('丢弃 script / iframe 等危险标签（含其中的文本）', () => {
    const out = sanitizeHtml('<p>ok</p><script>alert(1)</script>')
    expect(out).toContain('<p>ok</p>')
    expect(out).not.toContain('script')
    expect(out).not.toContain('alert')

    expect(sanitizeHtml('<iframe src="https://evil"></iframe>')).toBe('')
  })

  it('丢弃内联事件属性（存储型 XSS 的主要入口）', () => {
    const out = sanitizeHtml('<img src="x" onerror="alert(1)" alt="a">')
    expect(out).not.toContain('onerror')
    expect(out).not.toContain('alert')
    expect(out).toContain('alt="a"')
  })

  it('丢弃 javascript: 协议链接', () => {
    const out = sanitizeHtml('<a href="javascript:alert(1)">点我</a>')
    expect(out).not.toContain('javascript:')
  })

  it('丢弃 class / style / data-* 等非白名单属性', () => {
    const out = sanitizeHtml('<p class="x" style="color:red" data-node-id="1">t</p>')
    expect(out).toBe('<p>t</p>')
  })

  it('空输入返回空串', () => {
    expect(sanitizeHtml('')).toBe('')
  })
})

describe('isBlankHtml', () => {
  it('空串 / 编辑器空壳 / 纯空白都算空', () => {
    expect(isBlankHtml('')).toBe(true)
    expect(isBlankHtml('<p></p>')).toBe(true)
    expect(isBlankHtml('<p>&nbsp;</p>')).toBe(true)
    expect(isBlankHtml('<p>   </p>')).toBe(true)
    expect(isBlankHtml('  ')).toBe(true)
  })

  it('只有图片 / 分隔线时不算空', () => {
    expect(isBlankHtml('<p><img src="/a.png"></p>')).toBe(false)
    expect(isBlankHtml('<hr>')).toBe(false)
  })

  it('有文本时不算空', () => {
    expect(isBlankHtml('<p>hi</p>')).toBe(false)
  })
})

describe('normalizeHtml', () => {
  it('空壳归一为「空串」，非空内容去掉首尾空白', () => {
    expect(normalizeHtml('<p></p>')).toBe('')
    expect(normalizeHtml('<p>&nbsp;</p>')).toBe('')
    expect(normalizeHtml('  <p>hi</p>  ')).toBe('<p>hi</p>')
  })
})
