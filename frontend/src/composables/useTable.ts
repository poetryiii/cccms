import { getCurrentInstance, provide, reactive, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import type { PageResult } from '@/types/table'

/** 加载动画的最短可见时长（ms） */
const MIN_LOADING_MS = 300

/** 筛选条件自动记忆的存储前缀（沿用 `cccms_table_width:` 的命名风格） */
const FILTER_PREFIX = 'cccms_table_filter:'
/** 命名筛选方案的存储前缀 */
const FILTER_SCHEME_PREFIX = 'cccms_table_filter_scheme:'

/** 持久化的筛选快照：查询条件 + 每页条数（**不含 page**，页码不属于筛选方案） */
export interface TableFilterSnapshot {
  /** 查询条件（普通对象，JSON 可序列化） */
  query: Record<string, unknown>
  /** 每页条数 */
  limit: number
}

/**
 * 表格「筛选方案」控制器。
 *
 * `useTable` 在页面 setup 里把它 `provide` 出去，`ArtTable` 通过 `inject` 拿到，
 * 所以列表页**无需任何接线**即可在工具栏出现「筛选方案」入口。
 */
export interface TableFilterController {
  /** 当前表格的持久化标识（`defineOptions({ name })` 或路由 path） */
  key: string
  /** 本页是否已有自动记忆的筛选条件 */
  hasSaved: () => boolean
  /** 清除自动记忆（命名方案不受影响） */
  clearSaved: () => void
  /** 已保存的命名方案名 */
  listSchemes: () => string[]
  /** 把当前条件存为命名方案 */
  saveScheme: (name: string) => void
  /** 载入命名方案（回到第 1 页并重新查询） */
  applyScheme: (name: string) => void
  /** 删除命名方案 */
  removeScheme: (name: string) => void
}

/** ArtTable 通过它拿到所属页面 useTable 的筛选方案控制器 */
export const TABLE_FILTER_KEY: InjectionKey<TableFilterController> = Symbol('cccms-table-filter')

function readJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) {
      return null
    }
    const parsed: unknown = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? (parsed as T) : null
  } catch {
    // 解析异常时视为「无记忆」
    return null
  }
}

function writeJson(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // localStorage 不可用时忽略
  }
}

/** 展开成普通对象，避免把 reactive 代理直接序列化 */
function toQueryRecord(source: object): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(source)) {
    out[key] = value
  }
  return out
}

/**
 * 持久化标识：优先取页面 `defineOptions({ name })`（菜单 slug），
 * 拿不到时回落到当前路由 path —— 两者在同一页面内都稳定。
 */
function resolveFilterKey(): string {
  const instance = getCurrentInstance()
  const name = (instance?.type as { name?: string } | undefined)?.name
  if (name) {
    return name
  }
  try {
    return useRoute().path
  } catch {
    return 'default'
  }
}

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
 *
 * 「筛选方案」默认开启且零配置：当前条件（含 limit、不含 page）会自动写入
 * `localStorage['cccms_table_filter:<name>']`，下次进同一页面自动回填；
 * 命名方案存在 `localStorage['cccms_table_filter_scheme:<name>']`，入口由 ArtTable 工具栏提供。
 */
export function useTable<T = Record<string, unknown>, Q extends object = Record<string, unknown>>(
  options: UseTableOptions<T, Q>,
) {
  const list = ref([]) as Ref<T[]>
  const loading = ref(false)
  const total = ref(0)
  const page = ref(1)
  // 默认每页条数取自后台配置 ui.page_size
  const limit = ref(options.pageSize ?? useAppStore().pageSize)
  const selection = ref([]) as Ref<T[]>

  const initialQuery = { ...(options.initialQuery ?? ({} as Q)) } as Q
  const query = reactive({ ...initialQuery }) as Q

  /* ---- 筛选方案：自动记忆 + 命名方案（页面零配置） ---- */
  const filterKey = resolveFilterKey()
  const savedKey = FILTER_PREFIX + filterKey
  const schemeKey = FILTER_SCHEME_PREFIX + filterKey

  /**
   * 只回填页面声明过的字段（`initialQuery` 的 key 即白名单），
   * 避免旧版本存下、现已被页面删除的字段被重新发给接口；
   * 页面未声明 initialQuery 时退化为「原样回填」。
   */
  function normalizeQuery(raw: unknown): Record<string, unknown> {
    if (!raw || typeof raw !== 'object') {
      return {}
    }
    const source = raw as Record<string, unknown>
    const known = Object.keys(initialQuery)
    if (known.length === 0) {
      return { ...source }
    }
    const out: Record<string, unknown> = {}
    for (const key of known) {
      if (key in source) {
        out[key] = source[key]
      }
    }
    return out
  }

  function snapshot(): TableFilterSnapshot {
    return { query: toQueryRecord(query), limit: limit.value }
  }

  function applySnapshot(snap: TableFilterSnapshot): void {
    Object.assign(query, normalizeQuery(snap.query))
    if (typeof snap.limit === 'number' && snap.limit > 0) {
      limit.value = snap.limit
    }
  }

  // 进页面先回填上次的条件（含 limit，不含 page）；此时 watch 尚未注册，不会回写
  const stored = readJson<TableFilterSnapshot>(savedKey)
  if (stored) {
    applySnapshot(stored)
  }

  function readSchemes(): Record<string, TableFilterSnapshot> {
    return readJson<Record<string, TableFilterSnapshot>>(schemeKey) ?? {}
  }

  function persistCurrent(): void {
    writeJson(savedKey, snapshot())
  }

  function clearSaved(): void {
    try {
      localStorage.removeItem(savedKey)
    } catch {
      // localStorage 不可用时忽略
    }
  }

  function saveScheme(name: string): void {
    const schemes = readSchemes()
    schemes[name] = snapshot()
    writeJson(schemeKey, schemes)
  }

  function removeScheme(name: string): void {
    const schemes = readSchemes()
    if (!(name in schemes)) {
      return
    }
    delete schemes[name]
    writeJson(schemeKey, schemes)
  }

  function applyScheme(name: string): void {
    const snap = readSchemes()[name]
    if (!snap) {
      return
    }
    applySnapshot(snap)
    page.value = 1
    void load()
  }

  // 条件或每页条数一变就自动记忆（页码不存）
  watch(query, persistCurrent, { deep: true })
  watch(limit, persistCurrent)

  if (getCurrentInstance()) {
    provide(TABLE_FILTER_KEY, {
      key: filterKey,
      hasSaved: () => readJson<TableFilterSnapshot>(savedKey) !== null,
      clearSaved,
      listSchemes: () => Object.keys(readSchemes()),
      saveScheme,
      applyScheme,
      removeScheme,
    })
  }

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
