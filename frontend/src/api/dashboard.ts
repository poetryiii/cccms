import { http } from './request'

export interface DashboardCounts {
  user: number
  role: number
  dept: number
  post: number
  menu: number
  file: number
  log: number
  today_log: number
}

export interface DashboardStats {
  counts: DashboardCounts
  log_trend: { dates: string[]; values: number[] }
  file_types: { name: string; value: number }[]
}

export function dashboardStats() {
  return http.get<DashboardStats>('/dashboard/stats')
}
