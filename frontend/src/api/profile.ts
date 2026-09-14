import { http } from './request'

/** 个人中心：当前登录用户自己的资料（后端只认 token 里的身份，不接受传 id） */
export interface ProfileDetail {
  id: number
  username: string
  nickname: string
  avatar: string
  email: string
  phone: string
  status: number
  remark: string
  login_time: string | null
  login_ip: string
  create_time: string | null
  /** 角色名（含继承来的祖先角色） */
  roles: string[]
  /** 所属部门名 */
  depts: string[]
}

export function profileRead() {
  return http.get<ProfileDetail>('/profile')
}

/** 只能改 nickname / avatar / email / phone（后端白名单） */
export function profileUpdate(data: Record<string, unknown>) {
  return http.post<null>('/profile/update', data)
}

export function profileChangePassword(data: { old_password: string; new_password: string }) {
  return http.post<null>('/profile/password', data)
}
