import { describe, expect, it } from 'vitest'
import { md5 } from '../md5'

/**
 * 对照值取自 PHP 的 md5()（同一批输入），保证前后端算出来的是同一个 32 位串
 * —— 链接宏参数的值是给巨量与运营看的，两端不一致就等于每次刷新都换了码。
 */
describe('MD5', () => {
  it('公开测试向量', () => {
    expect(md5('')).toBe('d41d8cd98f00b204e9800998ecf8427e')
    expect(md5('abc')).toBe('900150983cd24fb0d6963f7d28e17f72')
    expect(md5('The quick brown fox jumps over the lazy dog')).toBe('9e107d9d372bb6826bd81d3542a419d6')
  })

  it('UTF-8 中文与数字串（与 PHP md5 一致）', () => {
    expect(md5('中文参数')).toBe('29bb2472f6c12bb552ed25a3e0aa287e')
    expect(md5('1791358462123abcdef')).toBe('c4b92a1ed420f26f371efee53277939e')
  })

  it('跨块输入（1000 个字符要走多块补位）', () => {
    expect(md5('a'.repeat(1000))).toBe('cabe45dcc9ae5b66ba86600cca6b8ba8')
  })

  it('输出恒为 32 位小写十六进制', () => {
    expect(md5(String(Date.now()) + 'x')).toMatch(/^[0-9a-f]{32}$/)
  })
})
