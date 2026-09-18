import { downloadFile, http } from './request'
import type { PageResult } from './types'

export function logList(params: Record<string, unknown>) {
  return http.get<PageResult>('/log', params)
}

/** 导出 CSV（按当前筛选与数据范围） */
export function logExport(params: Record<string, unknown>) {
  return downloadFile('/log/export', params, '操作日志.csv')
}

export function logDelete(ids: number[]) {
  return http.post<null>('/log/delete', { ids })
}
