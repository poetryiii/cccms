import { http } from './request'
import type { PageResult } from './types'

export function roleList(params: Record<string, unknown>) {
  return http.get<PageResult>('/role', params)
}

export function roleTree() {
  return http.get<Record<string, unknown>[]>('/role/tree')
}

export function roleRead(id: number) {
  return http.get<Record<string, unknown>>('/role/read', { id })
}

export function roleSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/role/save', data)
}

export function roleUpdate(data: Record<string, unknown>) {
  return http.post<null>('/role/update', data)
}

export function roleDelete(id: number) {
  return http.post<null>('/role/delete', { id })
}

/** 复制角色：档位与**自身**节点授权一并复制（status=0 即「另存为模板」） */
export function roleCopy(data: { id: number; name?: string; code?: string; parent_id?: number; status?: number }) {
  return http.post<{ id: number }>('/role/copy', data)
}
