import { http } from './request'
import type { PageResult } from './types'

export interface LogRow {
  id: number
  user_id: number
  username: string
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

/** 导出 CSV（按当前筛选与数据范围；一律异步，结果到全局「导出任务」面板下载） */
export function logExport(params: Record<string, unknown>) {
  return http.get<{ async: true; task_id: number; total: number }>('/log/export', params)
}

export function logDelete(ids: number[]) {
  return http.post<null>('/log/delete', { ids })
}

/** 一次请求的链路聚合结果（P2-6） */
export interface LogTrace {
  trace_id: string
  total: number
  failed: number
  /** 该链路所有记录的耗时之和（ms） */
  cost: number
  list: LogRow[]
}

/** 按 trace_id 聚合查看同一次请求的全部日志 */
export function logTrace(traceId: string) {
  return http.get<LogTrace>('/log/trace', { trace_id: traceId })
}

/** 登录安全分析（P2-13）：失败趋势 / TOP 用户名 / TOP IP / 异地登录 */
export interface LoginAnalysis {
  range: {
    start: string
    end: string
    /** hour = 窗口不超过 48 小时，day = 更长窗口 */
    granularity: 'hour' | 'day'
  }
  summary: {
    total: number
    success: number
    failed: number
    /** 百分比，已保留两位小数 */
    fail_rate: number
    users: number
    ips: number
  }
  trend: Array<{ bucket: string; success: number; failed: number }>
  top_users: Array<{ username: string; count: number }>
  top_ips: Array<{ ip: string; count: number }>
  ip_changes: Array<{ username: string; from_ip: string; to_ip: string; time: string }>
}

/**
 * 拉取登录分析。
 *
 * `days` 与 `start`/`end` 二选一：传了显式区间时后端优先用区间，
 * `days` 会被收敛到 [1, 90]。
 */
export function logLoginAnalysis(params: { days?: number; start?: string; end?: string } = {}) {
  return http.get<LoginAnalysis>('/log/login/analysis', params)
}
