import { http } from './request'

/**
 * 回收站的两个写操作。
 *
 * 列表刻意不在这里：页面上的「回收站」开关切的是**各模块自己的列表接口 + `trashed=1`**，
 * 同一张表、同一套列只换数据源，所以不需要单独的回收站列表接口。
 */
export function recycleRestore(data: { type: string; ids: number[] }) {
  return http.post<{ restored: number }>('/recycle/restore', data)
}

export function recycleDelete(data: { type: string; ids: number[] }) {
  return http.post<{ deleted: number }>('/recycle/delete', data)
}
