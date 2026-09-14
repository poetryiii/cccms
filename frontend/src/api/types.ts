export interface PageResult<T = Record<string, unknown>> {
  total: number
  list: T[]
}

export interface MenuNode {
  id: number
  parent_id: number
  type: number
  title: string
  path: string
  component: string
  icon: string
  sort: number
  node: string
  status: number
  children?: MenuNode[]
}
