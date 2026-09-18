import { http } from './request'
import type { PageResult } from './types'

export interface NoticeRow {
  id: number
  title: string
  /** 1 通知 · 2 公告 */
  type: number
  /** 1 普通 · 2 重要 */
  level: number
  content: string
  /** 1 已发布 · 0 草稿 */
  status: number
  publish_at: string | null
  expire_at: string | null
  read_count: number
  create_time: string
  /** 阅读侧返回：当前用户是否已读 */
  is_read?: boolean
}

// ---- 管理侧 ----

export function noticeList(params: Record<string, unknown>) {
  return http.get<PageResult<NoticeRow>>('/notice', params)
}

export function noticeRead(id: number) {
  return http.get<NoticeRow>('/notice/read', { id })
}

export function noticeSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/notice/save', data)
}

export function noticeUpdate(data: Record<string, unknown>) {
  return http.post<null>('/notice/update', data)
}

export function noticeDelete(id: number) {
  return http.post<null>('/notice/delete', { id })
}

// ---- 阅读侧（登录即可，只看自己的） ----

export function myNoticeList(params: Record<string, unknown>) {
  return http.get<PageResult<NoticeRow>>('/notice/my', params)
}

export function noticeUnreadCount() {
  return http.get<{ count: number }>('/notice/unread')
}

export function noticeMarkRead(id: number) {
  return http.post<null>('/notice/markRead', { id })
}

export function noticeMarkAllRead() {
  return http.post<{ count: number }>('/notice/markAllRead')
}
