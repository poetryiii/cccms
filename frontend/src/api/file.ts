import { http } from './request'
import type { PageResult } from './types'

export interface FileRow {
  id: number
  original_name: string
  name: string
  path: string
  url: string
  ext: string
  mime: string
  size: number
  /** 所属分类（0 = 未分类） */
  category_id?: number
  /** 后台补的分类名，避免前端再查一次 */
  category_name?: string
  create_time: string
  /** 是否图片（由后台 upload.image_ext 配置推导） */
  is_image?: boolean
}

export function fileList(params: Record<string, unknown>) {
  return http.get<PageResult<FileRow>>('/file', params)
}

export function fileDelete(id: number) {
  return http.post<null>('/file/delete', { id })
}

/** 批量移动到分类（categoryId <= 0 表示移出分类） */
export function fileMove(ids: number[], categoryId: number) {
  return http.post<{ count: number }>('/file/move', { ids, category_id: categoryId })
}

export const FILE_UPLOAD_URL = `${import.meta.env.VITE_API_BASE || '/api'}/file/upload`
