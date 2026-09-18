import { downloadFile, http } from './request'
import type { PageResult } from './types'

export interface LoginLogRow {
  id: number
  user_id: number
  username: string
  status: number
  message: string
  ip: string
  ua: string
  create_time: string
}

export function loginLogList(params: Record<string, unknown>) {
  return http.get<PageResult<LoginLogRow>>('/login_log', params)
}

export function loginLogExport(params: Record<string, unknown>) {
  return downloadFile('/login_log/export', params, '登录日志.csv')
}

export function loginLogDelete(ids: number[]) {
  return http.post<{ deleted: number }>('/login_log/delete', { ids })
}

export function loginLogClear() {
  return http.post<{ deleted: number }>('/login_log/clear')
}
