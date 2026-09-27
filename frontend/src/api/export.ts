import { downloadFile, http } from './request'

/** 导出任务状态：0待处理 1处理中 2完成 3失败 4已过期 */
export type ExportTaskStatus = 0 | 1 | 2 | 3 | 4

export interface ExportTaskRow {
  id: number
  type: string
  status: ExportTaskStatus
  total_rows: number
  file_name: string
  error: string
  create_time: string
}

/** 我的导出任务列表（最新在前） */
export function exportTaskList() {
  return http.get<ExportTaskRow[]>('/export/task/list')
}

/** 下载导出归档文件 */
export function exportTaskDownload(id: number) {
  return downloadFile('/export/task/download', { id })
}
