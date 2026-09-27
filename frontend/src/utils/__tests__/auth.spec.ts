import { beforeEach, describe, expect, it } from 'vitest'
import { clearToken, getToken, setToken } from '@/utils/auth'

describe('token 本机存储', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('未登录时返回空串（而不是 null，调用方不必再判空）', () => {
    expect(getToken()).toBe('')
  })

  it('写入后能读回，清除后回到空串', () => {
    setToken('abc.def.ghi')
    expect(getToken()).toBe('abc.def.ghi')
    expect(localStorage.getItem('cccms_token')).toBe('abc.def.ghi')

    clearToken()
    expect(getToken()).toBe('')
  })

  it('清除是幂等的（重复登出不应报错）', () => {
    clearToken()
    clearToken()
    expect(getToken()).toBe('')
  })
})
