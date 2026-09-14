<template>
  <div class="art-table">
    <!-- 搜索栏：提供 #search 插槽时才渲染 -->
    <el-card v-if="hasSearch" class="art-table-search" shadow="never">
      <el-form :inline="true" @submit.prevent>
        <slot name="search" />
        <el-form-item>
          <el-button type="primary" :icon="Search" @click="emit('search')">查询</el-button>
          <el-button :icon="RefreshLeft" @click="emit('reset')">重置</el-button>
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
            <el-tag type="warning" effect="dark" round>回收站模式</el-tag>
            <template v-if="selection">
              <el-button
                v-auth="'cccms:recycle:restore'"
                type="primary"
                :icon="RefreshLeft"
                :disabled="selected.length === 0"
                @click="emit('restore')"
              >
                还原选中{{ selected.length ? `（${selected.length}）` : '' }}
              </el-button>
              <el-button
                v-auth="'cccms:recycle:delete'"
                type="danger"
                plain
                :icon="Delete"
                :disabled="selected.length === 0"
                @click="emit('force-delete')"
              >
                彻底删除{{ selected.length ? `（${selected.length}）` : '' }}
              </el-button>
            </template>
          </template>
          <slot v-else name="toolbar" />
        </div>
        <div class="art-table-toolbar-right">
          <slot name="toolbar-right" />
          <el-tooltip content="刷新" placement="top">
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
                <span>列设置</span>
                <el-button link type="primary" size="small" @click="resetColumns">重置</el-button>
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
        <el-table
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
        >
          <!--
            刻意不开 reserve-selection：勾选状态跨刷新保留会留下「勾着但已不在当前列表里」的
            脏状态（例如批量移动附件后），批量操作容易误伤看不见的行。
          -->
          <el-table-column v-if="selection" type="selection" width="46" />

          <el-table-column
            v-for="col in visibleColumns"
            :key="col.prop"
            v-bind="columnProps(col)"
          >
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
          <el-table-column v-if="recycle" label="操作" width="170" fixed="right">
            <template #default="{ row }">
              <el-button
                v-auth="'cccms:recycle:restore'"
                link
                type="primary"
                @click="emit('restore', row)"
              >
                还原
              </el-button>
              <el-button
                v-auth="'cccms:recycle:delete'"
                link
                type="danger"
                @click="emit('force-delete', row)"
              >
                彻底删除
              </el-button>
            </template>
          </el-table-column>

          <template #empty>
            <el-empty description="暂无数据" :image-size="80" />
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
import { computed, ref, useSlots, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useMediaQuery } from '@vueuse/core'
import { Delete, Refresh, RefreshLeft, Search, Setting } from '@element-plus/icons-vue'
import type { ArtTableColumn } from '@/types/table'

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

const hasSearch = computed(() => !!slots.search)

const isNarrow = useMediaQuery('(max-width: 768px)')
const pagerLayout = computed(() =>
  isNarrow.value ? 'prev, pager, next' : 'total, sizes, prev, pager, next, jumper',
)

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
  hiddenColumns.value = visible
    ? hiddenColumns.value.filter((p) => p !== prop)
    : [...hiddenColumns.value, prop]
  persistHidden()
}

function resetColumns(): void {
  hiddenColumns.value = defaultHidden()
  persistHidden()
}

/**
 * 页面声明的列：回收站模式下「操作」列（约定 `slot='action'`）由表格统一接管，故剔除。
 * 列设置面板用的也是它 —— 否则会留下一个「勾了也不显示」的僵尸项。
 */
const ownColumns = computed(() =>
  props.recycle ? props.columns.filter((c) => c.slot !== 'action') : props.columns,
)

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
  if (col.width !== undefined) {
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
</style>
