import { http } from './request'
import type { MenuNode } from './types'

/** 菜单树；trashed=true 取回收站（平铺已删节点） */
export function menuTree(trashed = false) {
  return http.get<MenuNode[]>('/menu/tree', { trashed: trashed ? 1 : 0 })
}

export function userTree() {
  return http.get<MenuNode[]>('/menu/userTree')
}

export function menuSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/menu/save', data)
}

export function menuUpdate(data: Record<string, unknown>) {
  return http.post<null>('/menu/update', data)
}

export function menuDelete(id: number) {
  return http.post<null>('/menu/delete', { id })
}
