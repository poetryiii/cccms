<template>
  <div class="art-table">
    <!-- 搜索栏：提供 #search 插槽时才渲染 -->
    <el-card v-if="hasSearch" class="art-table-search" shadow="never">
      <el-form :inline="true" @submit.prevent>
        <slot name="search" />
        <el-form-item>
          <el-button type="primary" :icon="Search" @click="emit('search')">{{ t('table.query') }}</el-button>
          <el-button :icon="RefreshLeft" @click="emit('reset')">{{ t('table.reset') }}</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="art-table-main" shadow="never">
      <div class="art-table-toolbar">
        <div class="art-table-toolbar-left">
          <!--
            回收站模式：左侧操作区整体换成「还原 / 彻底删除」，页面的新增/批量操作等按钮不渲染
            （在回收站里做这些操作没有意义）。
          -->
          <template v-if="recycle">
            <el-tag type="warning" effect="dark" round>{{ t('table.recycleMode') }}</el-tag>
            <template v-if="selection">
              <el-button
                v-auth="'cccms:recycle:restore'"
                type="primary"
                :icon="RefreshLeft"
                :disabled="selected.length === 0"
                @click="emit('restore')"
              >
                {{ t('table.restoreSelected') }}{{ selected.length ? `（${selected.length}）` : '' }}
              </el-button>
              <el-button
                v-auth="'cccms:recycle:delete'"
                type="danger"
                plain
                :icon="Delete"
                :disabled="selected.length === 0"
                @click="emit('force-delete')"
              >
                {{ t('table.forceDeleteSelected') }}{{ selected.length ? `（${selected.length}）` : '' }}
              </el-button>
            </template>
          </template>
          <slot v-else name="toolbar" />
        </div>
        <div class="art-table-toolbar-right">
          <slot name="toolbar-right" />
          <!--
            筛选方案：控制器由页面 useTable 通过 provide 注入，页面无需接线。
            页面没用 useTable（如纯树表）时为 null，入口整体不渲染。
          -->
          <el-popover v-if="tableFilter" trigger="click" placement="bottom-end" :width="240" @show="refreshFilterPanel">
            <template #reference>
              <!-- 这里同样不能套 el-tooltip：popover 与 tooltip 都是 popper trigger，会互抢 click -->
              <el-button text circle :icon="Filter" :title="t('table.filterScheme')" />
            </template>
            <div class="art-table-filter">
              <div class="art-table-filter-head">
                <span>{{ t('table.filterScheme') }}</span>
                <el-button link type="primary" size="small" :disabled="!filterHasSaved" @click="clearFilterSaved">
                  {{ t('table.clearMemory') }}
                </el-button>
              </div>
              <div v-if="filterSchemes.length" class="art-table-filter-list">
                <div v-for="name in filterSchemes" :key="name" class="art-table-filter-item">
                  <el-button link type="primary" @click="applyFilterScheme(name)">{{ name }}</el-button>
                  <el-button link type="danger" @click="removeFilterScheme(name)">{{ t('table.delete') }}</el-button>
                </div>
              </div>
              <div v-else class="art-table-filter-empty">{{ t('table.noSavedScheme') }}</div>
              <div class="art-table-filter-save">
                <el-input
                  v-model="filterName"
                  size="small"
                  :placeholder="t('table.schemeName')"
                  @keyup.enter="saveFilterScheme"
                />
                <el-button size="small" type="primary" :disabled="!filterName.trim()" @click="saveFilterScheme">
                  {{ t('table.save') }}
                </el-button>
              </div>
            </div>
          </el-popover>
          <el-tooltip :content="t('table.refresh')" placement="top">
            <el-button text circle :icon="Refresh" :loading="loading" @click="emit('refresh')" />
          </el-tooltip>
          <!--
            不要在 #reference 里再套 el-tooltip：
            popover 与 tooltip 都基于 popper trigger，会互相抢 click 事件，导致面板点不开。
          -->
          <el-popover trigger="click" placement="bottom-end" :width="220">
            <template #reference>
              <el-button text circle :icon="Setting" />
            </template>
            <div class="art-table-columns">
              <div class="art-table-columns-head">
                <span>{{ t('table.columnSetting') }}</span>
                <el-button link type="primary" size="small" @click="resetColumns">{{ t('table.reset') }}</el-button>
              </div>
              <el-checkbox
                v-for="col in ownColumns"
                :key="col.prop"
                :model-value="!hiddenColumns.includes(col.prop)"
                :disabled="col.lockVisible"
                @change="(checked: string | number | boolean) => toggleColumn(col.prop, Boolean(checked))"
              >
                {{ col.label }}
              </el-checkbox>
            </div>
          </el-popover>
        </div>
      </div>

      <div class="art-table-wrap">
        <!-- 虚拟滚动模式：仅当开启 virtual 且非树形/非回收站时生效，用 el-table-v2 + el-auto-resizer -->
        <el-auto-resizer v-if="useVirtual">
          <template #default="{ height, width }">
            <el-table-v2
              v-loading="loading"
              :columns="virtualColumns"
              :data="data"
              :width="width"
              :height="height"
              :row-key="rowKey"
              :header-height="44"
              :row-height="48"
            >
              <template #empty>
                <el-empty :description="t('table.empty')" :image-size="80" />
              </template>
            </el-table-v2>
          </template>
        </el-auto-resizer>

        <el-table
          v-else
          :key="columnKey"
          v-loading="loading"
          :data="data"
          :row-key="rowKey"
          :height="height"
          :default-expand-all="tree"
          :tree-props="{ children: 'children' }"
          stripe
          border
          highlight-current-row
          @selection-change="onSelectionChange"
          @sort-change="onSortChange"
          @header-dragend="onHeaderDragend"
        >
          <!--
            刻意不开 reserve-selection：勾选状态跨刷新保留会留下「勾着但已不在当前列表里」的
            脏状态（例如批量移动附件后），批量操作容易误伤看不见的行。
          -->
          <el-table-column v-if="selection" type="selection" width="46" />

          <el-table-column v-for="col in visibleColumns" :key="col.prop" v-bind="columnProps(col)">
            <template v-if="col.slot" #default="scope">
              <slot
                :name="col.slot"
                :row="scope.row"
                :index="scope.$index"
                :value="(scope.row as Record<string, unknown>)[col.prop]"
              />
            </template>
          </el-table-column>

          <!-- 回收站模式：操作列由表格统一渲染，页面的「操作」列（slot=action）被换掉 -->
          <el-table-column v-if="recycle" :label="t('table.action')" width="170" fixed="right">
            <template #default="{ row }">
              <el-button v-auth="'cccms:recycle:restore'" link type="primary" @click="emit('restore', row)">
                {{ t('table.restore') }}
              </el-button>
              <el-button v-auth="'cccms:recycle:delete'" link type="danger" @click="emit('force-delete', row)">
                {{ t('table.forceDelete') }}
              </el-button>
            </template>
          </el-table-column>

          <template #empty>
            <el-empty :description="t('table.empty')" :image-size="80" />
          </template>
        </el-table>
      </div>

      <div v-if="pagination" class="art-table-pager">
        <div class="art-table-pager-left">
          <slot name="pager-left" />
        </div>
        <el-pagination
          :current-page="page"
          :page-size="limit"
          :total="total"
          :page-sizes="pageSizes"
          :layout="pagerLayout"
          background
          @current-change="onCurrentChange"
          @size-change="onSizeChange"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, h, inject, ref, useSlots, watch } from 'vue'
import type { VNode } from 'vue'
import { useRoute } from 'vue-router'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { ElCheckbox, ElMessage } from 'element-plus'
import { Delete, Filter, Refresh, RefreshLeft, Search, Setting } from '@element-plus/icons-vue'
import { TABLE_FILTER_KEY } from '@/composables/useTable'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

const props = withDefaults(
  defineProps<{
    columns: ArtTableColumn[]
    data: Record<string, any>[]
    loading?: boolean
    total?: number
    rowKey?: string
    /** 表格高度：'100%' 撑满父容器，也可传数字（px） */
    height?: number | string
    pageSizes?: number[]
    /** 是否显示多选列 */
    selection?: boolean
    /** 是否显示分页（树表等场景关掉） */
    pagination?: boolean
    /** 树形表格：开启后按 children 展开 */
    tree?: boolean
    /** 列显隐持久化的标识，默认按当前路由区分 */
    storageKey?: string
    /**
     * 回收站模式：数据由父页面换成「已删除」（后端 trashed=1），
     * 操作列统一换成「还原 / 彻底删除」，页面自带的 `slot: 'action'` 列与工具栏按钮不渲染。
     */
    recycle?: boolean
    /**
     * 大表格虚拟滚动：切换为 `el-table-v2`，只渲染可视区域内的行。
     * 仅对「非树形、非回收站」的普通列表生效，其余场景自动回退普通 `el-table`（行为完全不变）。
     * 适合单页行数达到数千以上的列表；普通分页列表无需开启。
     */
    virtual?: boolean
  }>(),
  {
    loading: false,
    total: 0,
    rowKey: 'id',
    height: '100%',
    pageSizes: () => [10, 15, 30, 50, 100],
    selection: false,
    pagination: true,
    tree: false,
    storageKey: '',
    recycle: false,
    virtual: false,
  },
)

const emit = defineEmits<{
  refresh: []
  search: []
  reset: []
  'page-change': [value: number]
  'size-change': [value: number]
  'sort-change': [value: { prop: string; order: string | null }]
  'selection-change': [rows: any[]]
  /** 还原：不传 row = 还原当前勾选 */
  restore: [row?: Record<string, any>]
  /** 彻底删除：不传 row = 彻底删除当前勾选 */
  'force-delete': [row?: Record<string, any>]
}>()

/** 分页由父页面双向绑定，方便 useTable 直接复用；无分页表格可不传 */
const page = defineModel<number>('page', { default: 1 })
const limit = defineModel<number>('limit', { default: 15 })

const slots = useSlots()
const route = useRoute()

/** 当前勾选行（仅用于回收站模式的批量按钮） */
const selected = ref<any[]>([])

/* ---- 筛选方案（控制器由页面 useTable provide，页面零配置） ---- */
const tableFilter = inject(TABLE_FILTER_KEY, null)
const filterSchemes = ref<string[]>([])
const filterName = ref('')
const filterHasSaved = ref(false)

function refreshFilterPanel(): void {
  filterSchemes.value = tableFilter?.listSchemes() ?? []
  filterHasSaved.value = tableFilter?.hasSaved() ?? false
}

function saveFilterScheme(): void {
  const name = filterName.value.trim()
  if (!tableFilter || !name) {
    return
  }
  tableFilter.saveScheme(name)
  filterName.value = ''
  refreshFilterPanel()
  ElMessage.success(t('table.savedScheme', { name }))
}

function applyFilterScheme(name: string): void {
  tableFilter?.applyScheme(name)
  refreshFilterPanel()
}

function removeFilterScheme(name: string): void {
  tableFilter?.removeScheme(name)
  refreshFilterPanel()
}

function clearFilterSaved(): void {
  tableFilter?.clearSaved()
  refreshFilterPanel()
  ElMessage.success(t('table.clearedMemory'))
}

const hasSearch = computed(() => !!slots.search)

const isNarrow = useMediaQuery('(max-width: 768px)')
const pagerLayout = computed(() => (isNarrow.value ? 'prev, pager, next' : 'total, sizes, prev, pager, next, jumper'))

/* ---- 列显隐（按页面持久化） ---- */
const HIDDEN_PREFIX = 'cccms_table_hidden:'

function storageId(): string {
  return props.storageKey || route.path
}

function defaultHidden(): string[] {
  return props.columns.filter((c) => c.defaultHidden).map((c) => c.prop)
}

function loadHidden(): string[] {
  try {
    const raw = localStorage.getItem(HIDDEN_PREFIX + storageId())
    if (raw) {
      return JSON.parse(raw) as string[]
    }
  } catch {
    // 忽略解析异常，回落到默认值
  }
  return defaultHidden()
}

function persistHidden(): void {
  try {
    localStorage.setItem(HIDDEN_PREFIX + storageId(), JSON.stringify(hiddenColumns.value))
  } catch {
    // localStorage 不可用时忽略
  }
}

const hiddenColumns = ref<string[]>(loadHidden())

watch(
  () => props.columns,
  () => {
    hiddenColumns.value = loadHidden()
  },
)

/** 直接按目标可见性写入，避免依赖「取反」推算导致的状态错位 */
function toggleColumn(prop: string, visible: boolean): void {
  hiddenColumns.value = visible ? hiddenColumns.value.filter((p) => p !== prop) : [...hiddenColumns.value, prop]
  persistHidden()
}

function resetColumns(): void {
  hiddenColumns.value = defaultHidden()
  persistHidden()
  // 列宽也一并恢复默认，否则「重置」只重置了一半
  columnWidths.value = {}
  persistWidths()
}

/* ---- 列宽记忆（拖拽表头后按页面持久化） ---- */
const WIDTH_PREFIX = 'cccms_table_width:'

function loadWidths(): Record<string, number> {
  try {
    const raw = localStorage.getItem(WIDTH_PREFIX + storageId())
    if (raw) {
      const parsed = JSON.parse(raw) as Record<string, number>
      return parsed && typeof parsed === 'object' ? parsed : {}
    }
  } catch {
    // 解析异常时回落为「无自定义列宽」
  }
  return {}
}

function persistWidths(): void {
  try {
    localStorage.setItem(WIDTH_PREFIX + storageId(), JSON.stringify(columnWidths.value))
  } catch {
    // localStorage 不可用时忽略
  }
}

const columnWidths = ref<Record<string, number>>(loadWidths())

watch(
  () => props.columns,
  () => {
    columnWidths.value = loadWidths()
  },
)

/** el-table 的 header-dragend：column.property 即列的 prop */
function onHeaderDragend(newWidth: number, _oldWidth: number, column: { property?: string }): void {
  const prop = column?.property
  if (!prop) {
    return
  }
  columnWidths.value = { ...columnWidths.value, [prop]: Math.round(newWidth) }
  persistWidths()
}

/**
 * 页面声明的列：回收站模式下「操作」列（约定 `slot='action'`）由表格统一接管，故剔除。
 * 列设置面板用的也是它 —— 否则会留下一个「勾了也不显示」的僵尸项。
 */
const ownColumns = computed(() => (props.recycle ? props.columns.filter((c) => c.slot !== 'action') : props.columns))

const visibleColumns = computed(() =>
  ownColumns.value.filter((c) => c.lockVisible || !hiddenColumns.value.includes(c.prop)),
)

/**
 * 可见列集合的指纹，作为 el-table 的 key。
 *
 * el-table 的列是在子组件挂载时注册的，动态增删列在部分版本下不会重算列宽与布局，
 * 表现就是「勾了列设置但表格没变」。用 key 强制重挂载最稳妥，
 * 列设置属于低频操作，重挂载代价可以接受。
 */
const columnKey = computed(() => visibleColumns.value.map((c) => c.prop).join('|'))

function columnProps(col: ArtTableColumn): Record<string, unknown> {
  const out: Record<string, unknown> = {
    prop: col.prop,
    label: col.label,
    align: col.align ?? 'left',
    headerAlign: col.align ?? 'left',
    showOverflowTooltip: col.showOverflowTooltip ?? true,
  }
  // 拖拽过的列宽优先于页面声明的宽度（用户意图优先）
  const savedWidth = columnWidths.value[col.prop]
  if (savedWidth) {
    out.width = savedWidth
  } else if (col.width !== undefined) {
    out.width = col.width
  }
  if (col.minWidth !== undefined) {
    out.minWidth = col.minWidth
  }
  if (col.fixed !== undefined) {
    out.fixed = col.fixed
  }
  if (col.sortable !== undefined) {
    out.sortable = col.sortable
  }
  return out
}

/* ---- 虚拟滚动（P2-15） ---- */

/** 树形 / 回收站场景不支持 el-table-v2，自动回退普通表格 */
const useVirtual = computed(() => props.virtual && !props.tree && !props.recycle)

const VIRTUAL_DEFAULT_WIDTH = 150

/** 虚拟模式下被勾选的行主键集合 */
const virtualSelectedKeys = ref<(string | number)[]>([])

function virtualRowKey(row: any): string | number {
  return row?.[props.rowKey] ?? row?.id
}

const virtualAllChecked = computed(
  () => props.data.length > 0 && virtualSelectedKeys.value.length === props.data.length,
)
const virtualIndeterminate = computed(
  () => virtualSelectedKeys.value.length > 0 && virtualSelectedKeys.value.length < props.data.length,
)

function syncVirtualSelection(): void {
  const rows = props.data.filter((row) => virtualSelectedKeys.value.includes(virtualRowKey(row)))
  // 复用普通模式的勾选状态与 selection-change 事件，页面无需区分实现
  selected.value = rows
  emit('selection-change', rows)
}

function toggleVirtualAll(checked: string | number | boolean | undefined): void {
  virtualSelectedKeys.value = checked ? props.data.map((row) => virtualRowKey(row)) : []
  syncVirtualSelection()
}

function toggleVirtualRow(row: any): void {
  const key = virtualRowKey(row)
  const idx = virtualSelectedKeys.value.indexOf(key)
  if (idx >= 0) {
    virtualSelectedKeys.value = virtualSelectedKeys.value.filter((k) => k !== key)
  } else {
    virtualSelectedKeys.value = [...virtualSelectedKeys.value, key]
  }
  syncVirtualSelection()
}

/** 把 ArtTable 的列契约映射成 el-table-v2 的 Column（width 必填，插槽用 cellRenderer 自绘） */
const virtualColumns = computed<Record<string, any>[]>(() => {
  const cols: Record<string, any>[] = []
  if (props.selection) {
    cols.push({
      key: '__selection',
      width: 46,
      align: 'center',
      headerCellRenderer: () =>
        h(ElCheckbox, {
          modelValue: virtualAllChecked.value,
          indeterminate: virtualIndeterminate.value,
          onChange: toggleVirtualAll,
        }),
      cellRenderer: ({ rowData }: { rowData: any }) =>
        h(ElCheckbox, {
          modelValue: virtualSelectedKeys.value.includes(virtualRowKey(rowData)),
          onChange: () => toggleVirtualRow(rowData),
        }),
    })
  }
  for (const col of visibleColumns.value) {
    let width = columnWidths.value[col.prop]
    if (!width && typeof col.width === 'number') {
      width = col.width
    }
    if (!width && typeof col.minWidth === 'number') {
      width = col.minWidth
    }
    if (!width) {
      width = VIRTUAL_DEFAULT_WIDTH
    }
    const item: Record<string, any> = {
      key: col.prop,
      dataKey: col.prop,
      title: col.label,
      width,
    }
    if (col.align) {
      item.align = col.align
    }
    if (col.fixed === true || col.fixed === 'left') {
      item.fixed = 'left'
    } else if (col.fixed === 'right') {
      item.fixed = 'right'
    }
    if (col.slot && slots[col.slot]) {
      const slotFn = slots[col.slot]!
      item.cellRenderer = ({
        rowData,
        rowIndex,
        cellData,
      }: {
        rowData: any
        rowIndex: number
        cellData: unknown
      }): VNode => h('div', slotFn({ row: rowData, index: rowIndex, value: cellData }))
    }
    cols.push(item)
  }
  return cols
})

/**
 * 翻页 / 刷新后剔除已不在当前数据里的勾选，避免批量操作误伤看不见的行
 * （与普通表格不开 reserve-selection 的语义保持一致）。
 */
watch(
  () => props.data,
  () => {
    if (!useVirtual.value || virtualSelectedKeys.value.length === 0) {
      return
    }
    const keys = props.data.map((row) => virtualRowKey(row))
    const next = virtualSelectedKeys.value.filter((key) => keys.includes(key))
    if (next.length !== virtualSelectedKeys.value.length) {
      virtualSelectedKeys.value = next
      syncVirtualSelection()
    }
  },
)

function onCurrentChange(value: number): void {
  page.value = value
  emit('page-change', value)
}

function onSizeChange(value: number): void {
  limit.value = value
  emit('size-change', value)
}

function onSelectionChange(rows: any[]): void {
  // 回收站模式的批量按钮需要知道勾了几条
  selected.value = rows
  emit('selection-change', rows)
}

function onSortChange(payload: { prop: string | null; order: string | null }): void {
  emit('sort-change', { prop: payload.prop ?? '', order: payload.order })
}
</script>

<style scoped>
.art-table {
  display: flex;
  /* flex:1 兼容被放进 flex 父容器（如 ArtSplitView 右栏）的场景，height:100% 兜底普通父容器 */
  flex: 1;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.art-table-search {
  flex-shrink: 0;
  margin-bottom: 12px;
}

.art-table-search :deep(.el-card__body) {
  padding: 16px 16px 0;
}

.art-table-search :deep(.el-form-item) {
  margin-bottom: 16px;
}

.art-table-main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.art-table-main :deep(.el-card__body) {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
  padding: 12px 16px 14px;
}

.art-table-toolbar {
  display: flex;
  flex-shrink: 0;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.art-table-toolbar-left {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.art-table-toolbar-right {
  display: flex;
  flex-shrink: 0;
  gap: 2px;
  align-items: center;
}

.art-table-wrap {
  flex: 1;
  min-height: 0;
}

.art-table-pager {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  padding-top: 12px;
}

.art-table-pager-left {
  font-size: 13px;
  color: var(--art-sub);
}

.art-table-columns {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 280px;
  overflow-y: auto;
}

.art-table-columns-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 6px;
  margin-bottom: 4px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-bottom: 1px solid var(--art-card-border);
}

.art-table-filter {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.art-table-filter-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-bottom: 1px solid var(--art-card-border);
}

.art-table-filter-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 200px;
  overflow-y: auto;
}

.art-table-filter-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.art-table-filter-item :deep(.el-button) {
  padding: 0;
}

.art-table-filter-empty {
  padding: 8px 0;
  font-size: 12px;
  color: var(--art-muted);
  text-align: center;
}

.art-table-filter-save {
  display: flex;
  gap: 6px;
}
</style>
