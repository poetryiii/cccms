import { http } from './request'
import type { PageResult } from './types'

export function postList(params: Record<string, unknown>) {
  return http.get<PageResult>('/post', params)
}

export function postSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/post/save', data)
}

export function postUpdate(data: Record<string, unknown>) {
  return http.post<null>('/post/update', data)
}

export function postDelete(id: number) {
  return http.post<null>('/post/delete', { id })
}
