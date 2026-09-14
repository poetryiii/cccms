import { http } from './request'
import type { PageResult } from './types'

export function logList(params: Record<string, unknown>) {
  return http.get<PageResult>('/log', params)
}

export function logDelete(ids: number[]) {
  return http.post<null>('/log/delete', { ids })
}
