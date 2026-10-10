import { http } from './request'

export interface DeptNode {
  id: number
  name?: string
  leader?: string
  phone?: string
  email?: string
  sort?: number
  status?: number
  parent_id?: number
  children?: DeptNode[]
}

/** 部门树；trashed=true 取回收站（平铺已删部门） */
export function deptTree(trashed = false) {
  return http.get<DeptNode[]>('/dept/tree', { trashed: trashed ? 1 : 0 })
}

export function deptSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/dept/save', data)
}

export function deptUpdate(data: Record<string, unknown>) {
  return http.post<null>('/dept/update', data)
}

export function deptDelete(id: number) {
  return http.post<null>('/dept/delete', { id })
}
