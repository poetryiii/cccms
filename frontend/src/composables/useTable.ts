import { reactive, ref, type Ref } from 'vue'
import { useAppStore } from '@/stores/app'
import type { PageResult } from '@/types/table'

/** 加载动画的最短可见时长（ms） */
const MIN_LOADING_MS = 300

export interface UseTableOptions<T, Q extends object> {
  /**
   * 列表请求：会带上 query / page / limit。
   * 返回体用 PageResult<unknown> 接收，避免每个接口都得把行类型透传成泛型。
   */
  api: (params: Record<string, unknown>) => Promise<PageResult<unknown>>
  /** 查询条件初始值（重置时回到这里） */
  initialQuery?: Q
  /** 每页条数，默认 15 */
  pageSize?: number
  /** 是否在 setup 阶段立即加载，默认 true */
  immediate?: boolean
  /** 请求成功后的数据加工（如补全 URL、格式化字段） */
  transform?: (list: T[]) => T[]
}

/**
 * 列表页通用逻辑：查询 / 分页 / 加载 / 多选。
 *
 * 用法：
 *   interface Query { username?: string; status?: number }
 *   const table = useTable<UserRow, Query>({ api: userList, initialQuery: { username: '' } })
 *   table.query.username / table.list / table.total ...
 */
export function useTable<
  T = Record<string, unknown>,
  Q extends object = Record<string, unknown>,
>(options: UseTableOptions<T, Q>) {
  const list = ref([]) as Ref<T[]>
  const loading = ref(false)
  const total = ref(0)
  const page = ref(1)
  // 默认每页条数取自后台配置 ui.page_size
  const limit = ref(options.pageSize ?? useAppStore().pageSize)
  const selection = ref([]) as Ref<T[]>

  const initialQuery = { ...(options.initialQuery ?? ({} as Q)) } as Q
  const query = reactive({ ...initialQuery }) as Q

  async function load(): Promise<void> {
    loading.value = true
    const startedAt = Date.now()
    try {
      const res = await options.api({ ...query, page: page.value, limit: limit.value })
      const rows = (res?.list ?? []) as T[]
      list.value = options.transform ? options.transform(rows) : rows
      total.value = res?.total ?? 0
    } finally {
      // 本地接口往往十几毫秒就返回，加载动画一闪而过会让人以为「没刷新」。
      // 数据已经在上面赋值了，这里只是让 loading 至少可见一小段时间。
      const rest = MIN_LOADING_MS - (Date.now() - startedAt)
      if (rest > 0) {
        await new Promise<void>((resolve) => {
          window.setTimeout(resolve, rest)
        })
      }
      loading.value = false
    }
  }

  /** 条件查询：回到第 1 页 */
  function search(): void {
    page.value = 1
    void load()
  }

  /** 重置查询条件并重新加载 */
  function reset(): void {
    Object.assign(query, initialQuery)
    page.value = 1
    void load()
  }

  function onPageChange(value: number): void {
    page.value = value
    void load()
  }

  function onLimitChange(value: number): void {
    limit.value = value
    page.value = 1
    void load()
  }

  function onSelectionChange(rows: T[]): void {
    selection.value = rows
  }

  if (options.immediate !== false) {
    void load()
  }

  return {
    list,
    loading,
    total,
    page,
    limit,
    query,
    selection,
    load,
    search,
    reset,
    onPageChange,
    onLimitChange,
    onSelectionChange,
  }
}
