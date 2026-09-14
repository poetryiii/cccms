import { http } from './request'
import type { PageResult } from './types'

export function userList(params: Record<string, unknown>) {
  return http.get<PageResult>('/user', params)
}

export function userRead(id: number) {
  return http.get<Record<string, unknown>>('/user/read', { id })
}

export function userSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/user/save', data)
}

export function userUpdate(data: Record<string, unknown>) {
  return http.post<null>('/user/update', data)
}

export function userDelete(id: number) {
  return http.post<null>('/user/delete', { id })
}

export function userResetPassword(data: { id: number; password: string }) {
  return http.post<null>('/user/resetPassword', data)
}
