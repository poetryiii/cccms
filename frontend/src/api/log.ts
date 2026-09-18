import { downloadFile, http } from './request'
import type { PageResult } from './types'

export interface LogRow {
  id: number
  user_id: number
  username: string
  /** 日志类型 operation 操作 / login 登录 */
  type: string
  /** 1 成功 / 0 失败 */
  status: number
  /** 结果说明（登录失败原因 / 操作异常信息） */
  message: string
  method: string
  path: string
  node: string
  title: string
  params: string
  result: string
  ip: string
  ua: string
  status_code: number
  cost: number
  trace_id: string
  create_time: string
}

export function logList(params: Record<string, unknown>) {
  return http.get<PageResult<LogRow>>('/log', params)
}

/** 导出 CSV（按当前筛选与数据范围） */
export function logExport(params: Record<string, unknown>) {
  return downloadFile('/log/export', params, '操作日志.csv')
}

export function logDelete(ids: number[]) {
  return http.post<null>('/log/delete', { ids })
}
