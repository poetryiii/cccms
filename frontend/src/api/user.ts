import { downloadFile, http } from './request'
import type { PageResult } from './types'

export function userList(params: Record<string, unknown>) {
  return http.get<PageResult>('/user', params)
}

export function userRead(id: number) {
  return http.get<Record<string, unknown>>('/user/read', { id })
}

export function userSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/user/save', data)
}

export function userUpdate(data: Record<string, unknown>) {
  return http.post<null>('/user/update', data)
}

export function userDelete(id: number) {
  return http.post<null>('/user/delete', { id })
}

export function userResetPassword(data: { id: number; password: string }) {
  return http.post<null>('/user/resetPassword', data)
}

/** 导出 CSV（按当前筛选与数据范围） */
export function userExport(params: Record<string, unknown>) {
  return downloadFile('/user/export', params, '用户列表.csv')
}

/** 下载导入模板 */
export function userTemplate() {
  return downloadFile('/user/template', undefined, '用户导入模板.csv')
}

/** 导入接口地址（走 el-upload 直传，与附件上传同样的做法） */
export const USER_IMPORT_URL = `${import.meta.env.VITE_API_BASE || '/api'}/user/import`

export interface UserImportResult {
  total: number
  created: number
  updated: number
  failed: string[]
}
