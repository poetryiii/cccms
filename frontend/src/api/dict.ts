import { http } from './request'
import type { PageResult } from './types'

export function dictTypeList(params: Record<string, unknown>) {
  return http.get<PageResult>('/dict', params)
}

export function dictTypeSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/dict/save', data)
}

export function dictTypeUpdate(data: Record<string, unknown>) {
  return http.post<null>('/dict/update', data)
}

export function dictTypeDelete(id: number) {
  return http.post<null>('/dict/delete', { id })
}

/** 字典数据；trashed=true 取回收站（只看该类型下已删的数据） */
export function dictDataList(typeId: number, trashed = false) {
  return http.get<Record<string, unknown>[]>('/dict/data', {
    type_id: typeId,
    trashed: trashed ? 1 : 0,
  })
}

export function dictDataSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/dict/saveData', data)
}

export function dictDataUpdate(data: Record<string, unknown>) {
  return http.post<null>('/dict/updateData', data)
}

export function dictDataDelete(id: number) {
  return http.post<null>('/dict/deleteData', { id })
}
