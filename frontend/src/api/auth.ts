import { http } from './request'

export interface LoginResult {
  token: { token: string; expires_in: number; expires_at: number }
  user: Record<string, unknown>
}

export interface Profile {
  id: number
  username: string
  nickname: string
  avatar: string
  super_admin: boolean
  roles: string[]
  permissions: string[]
  crypto_key?: string
}

/** 图形验证码（后端未实现时 data 为 null，前端据此隐藏该输入项） */
export interface CaptchaResult {
  captcha_id: string
  image: string
}

export interface LoginParams {
  username: string
  password: string
  captcha?: string
  captcha_id?: string
}

export function login(data: LoginParams) {
  return http.post<LoginResult>('/auth/login', data)
}

export function logout() {
  return http.post<null>('/auth/logout')
}

export function me() {
  return http.get<Profile>('/auth/me')
}

export function captcha() {
  return http.get<CaptchaResult | null>('/auth/captcha')
}

export function ping() {
  return http.get<{ pong: boolean; time: string }>('/ping')
}
