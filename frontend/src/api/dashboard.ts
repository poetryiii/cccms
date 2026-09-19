import { http } from './request'

export interface DashboardCounts {
  /**
   * 各项计数均**按权限节点逐项下发**（超管 `hasPermission()` 直通，因此永远拿全）：
   * 当前账号进不去对应列表页时该键不存在，前端据此不渲染对应卡片。
   */
  user?: number
  role?: number
  dept?: number
  post?: number
  menu?: number
  file?: number
  log?: number
  today_log?: number
  online?: number
  dict?: number
  crontab?: number
  data_rule?: number
  tenant?: number
  /** 我的未读消息（阅读侧，登录即可见，恒返回） */
  notice_unread: number
}

export interface DashboardStats {
  counts: DashboardCounts
  /** 无日志列表页权限时后端返回空数组（`dates` 为空 = 不渲染趋势卡） */
  log_trend: { dates: string[]; values: number[] }
  /** 无附件列表页权限时后端返回空数组 */
  file_types: { name: string; value: number }[]
}

export function dashboardStats() {
  return http.get<DashboardStats>('/dashboard/stats')
}
