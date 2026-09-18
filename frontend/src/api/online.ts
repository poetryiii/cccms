import { http } from './request'
import type { PageResult } from './types'

export interface OnlineSession {
  /** 会话 ID（令牌 jti），强制下线的目标 */
  jti: string
  user_id: number
  username: string
  nickname: string
  ip: string
  ua: string
  login_at: string
  last_at: string
}

export function onlineList(params: Record<string, unknown>) {
  return http.get<PageResult<OnlineSession>>('/online', params)
}

/** 强制下线单个会话 */
export function onlineKick(jti: string) {
  return http.post<null>('/online/kick', { jti })
}

/** 强制某用户的所有会话下线 */
export function onlineKickUser(userId: number) {
  return http.post<{ sessions: number }>('/online/kickUser', { user_id: userId })
}
