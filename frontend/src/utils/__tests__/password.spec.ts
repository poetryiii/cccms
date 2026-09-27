import { describe, expect, it } from 'vitest'
import { checkPassword, passwordValidator } from '@/utils/password'

describe('checkPassword', () => {
  it('合规口令返回空串（含大小写 + 数字两类即可）', () => {
    expect(checkPassword('Abcd1234')).toBe('')
    expect(checkPassword('abcd1234', { username: 'someone' })).toBe('')
  })

  it('长度下限 / 上限', () => {
    expect(checkPassword('Ab1')).toContain('至少')
    expect(checkPassword('Ab1'.padEnd(65, 'x'))).toContain('不能超过')
    // 恰好 64 位应通过长度检查（此处字符类别单一，因此报的是类别问题）
    expect(checkPassword('a'.repeat(64))).toContain('类')
  })

  it('拒绝与用户名 / 昵称完全相同（大小写不敏感）', () => {
    expect(checkPassword('Admin123', { username: 'admin123' })).toContain('用户名相同')
    expect(checkPassword('nick1234', { nickname: 'NICK1234' })).toContain('昵称相同')
  })

  it('拒绝包含用户名 / 昵称（长度 >= 4 才做包含判断）', () => {
    expect(checkPassword('xxAdmin123xx', { username: 'admin' })).toContain('包含用户名')
    // 身份信息短于 4 个字符时不做包含判断，避免误报
    expect(checkPassword('adm123456', { username: 'adm' })).toBe('')
  })

  it('身份信息为空 / 纯空白时跳过身份校验', () => {
    expect(checkPassword('Abcd1234', { username: '   ', nickname: '' })).toBe('')
    expect(checkPassword('Abcd1234', {})).toBe('')
  })

  it('字符类别不足时给出类别提示', () => {
    expect(checkPassword('abcdefgh')).toContain('类')
    expect(checkPassword('12345678')).toContain('类')
  })

  it('校验顺序：先长度、再身份、最后字符类别', () => {
    // 既太短又与用户名相同 → 报长度（长度是更基础的问题）
    expect(checkPassword('ab', { username: 'ab' })).toContain('至少')
  })
})

describe('passwordValidator', () => {
  it('通过时回调不传错误，失败时回调 Error', () => {
    const validator = passwordValidator()

    const passErrors: Array<Error | undefined> = []
    validator({}, 'Abcd1234', (error) => passErrors.push(error))
    expect(passErrors).toEqual([undefined])

    const failErrors: Array<Error | undefined> = []
    validator({}, 'abcdefgh', (error) => failErrors.push(error))
    expect(failErrors[0]).toBeInstanceOf(Error)
    expect(failErrors[0]?.message).toContain('类')
  })

  it('空值按不通过处理（必填由 FormRules 的 required 负责，这里不额外放宽）', () => {
    const errors: Array<Error | undefined> = []
    passwordValidator()({}, '', (error) => errors.push(error))
    expect(errors[0]).toBeInstanceOf(Error)
  })

  it('身份信息每次校验时实时读取（而非构造时的快照）', () => {
    let username = ''
    const validator = passwordValidator(() => ({ username }))

    const first: Array<Error | undefined> = []
    validator({}, 'admin123', (error) => first.push(error))
    expect(first[0]).toBeUndefined()

    // 表单填上用户名后再校验，应立刻按新值判定
    username = 'admin123'
    const second: Array<Error | undefined> = []
    validator({}, 'admin123', (error) => second.push(error))
    expect(second[0]).toBeInstanceOf(Error)
  })
})
