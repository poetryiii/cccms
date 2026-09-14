import { http } from './request'
import type { PageResult } from './types'

export interface CrontabRow {
  id: number
  name: string
  expression: string
  target: string
  params: unknown
  status: number
  remark: string
  last_run_time: string | null
  next_run_time: string | null
}

export interface TaskTarget {
  class: string
  label: string
}

export interface RunResult {
  status: number
  output: string
  cost: number
}

export function crontabList(params: Record<string, unknown>) {
  return http.get<PageResult<CrontabRow>>('/crontab', params)
}

export function crontabTargets() {
  return http.get<TaskTarget[]>('/crontab/targets')
}

export function crontabSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/crontab/save', data)
}

export function crontabUpdate(data: Record<string, unknown>) {
  return http.post<null>('/crontab/update', data)
}

export function crontabDelete(id: number) {
  return http.post<null>('/crontab/delete', { id })
}

export function crontabRun(id: number) {
  return http.post<RunResult>('/crontab/run', { id })
}

export function crontabLogs(params: Record<string, unknown>) {
  return http.get<PageResult>('/crontab/logs', params)
}
