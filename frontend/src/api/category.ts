import { http } from './request'

/**
 * 通用分类接口（sys_category）。
 *
 * 后端按模块拆了 slug（cccms:dict:category_* / cccms:file:category_*），
 * 但接口路径只是 /{module}/category*，所以这里用一个 module 参数区分。
 */
export type CategoryModule = 'dict' | 'file'

export interface CategoryNode {
  id: number
  module: string
  parent_id: number
  name: string
  sort: number
  status: number
  remark?: string
  children?: CategoryNode[]
}

/** 分类树；trashed=true 取回收站（平铺已删分类） */
export function categoryTree(module: CategoryModule, trashed = false) {
  return http.get<CategoryNode[]>(`/${module}/category`, { trashed: trashed ? 1 : 0 })
}

export function categorySave(module: CategoryModule, data: Record<string, unknown>) {
  return http.post<{ id: number }>(`/${module}/category/save`, data)
}

export function categoryUpdate(module: CategoryModule, data: Record<string, unknown>) {
  return http.post<null>(`/${module}/category/update`, data)
}

export function categoryDelete(module: CategoryModule, id: number) {
  return http.post<null>(`/${module}/category/delete`, { id })
}

/** 把分类树拍平（下拉选择用，带缩进名） */
export function flattenCategories(nodes: CategoryNode[], depth = 0): Array<CategoryNode & { indentName: string }> {
  const out: Array<CategoryNode & { indentName: string }> = []
  for (const node of nodes) {
    out.push({ ...node, indentName: `${'　'.repeat(depth)}${node.name}` })
    if (node.children?.length) {
      out.push(...flattenCategories(node.children, depth + 1))
    }
  }
  return out
}
