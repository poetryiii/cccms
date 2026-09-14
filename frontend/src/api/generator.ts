import { http } from './request'

export interface TableInfo {
  name: string
  comment: string
}

export interface ColumnInfo {
  name: string
  type: string
  comment: string
  primary: boolean
}

export interface GeneratedFile {
  path: string
  content: string
}

export interface GenerateResult {
  files: string[]
  menu: { slug: string; title: string; path: string; component: string }
  menu_snippet: string
}

export function generatorTables() {
  return http.get<TableInfo[]>('/generator/tables')
}

export function generatorColumns(table: string) {
  return http.get<ColumnInfo[]>('/generator/columns', { table })
}

export function generatorPreview(config: Record<string, unknown>) {
  return http.post<GeneratedFile[]>('/generator/preview', config)
}

export function generatorGenerate(config: Record<string, unknown>) {
  return http.post<GenerateResult>('/generator/generate', config)
}
