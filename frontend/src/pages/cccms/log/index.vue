<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      selection
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @search="search"
      @reset="reset"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @selection-change="onSelectionChange"
    >
      <template #search>
        <el-form-item :label="t('log.result')">
          <el-select v-model="query.status" :placeholder="t('log.all')" clearable style="width: 110px">
            <el-option :label="t('log.success')" :value="1" />
            <el-option :label="t('log.failed')" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('log.username')">
          <el-input
            v-model="query.username"
            :placeholder="t('log.usernamePlaceholder')"
            clearable
            style="width: 150px"
          />
        </el-form-item>
        <el-form-item :label="t('log.actionName')">
          <el-input
            v-model="query.title"
            :placeholder="t('log.actionNamePlaceholder')"
            clearable
            style="width: 180px"
          />
        </el-form-item>
        <el-form-item :label="t('log.path')">
          <el-input v-model="query.path" :placeholder="t('log.pathPlaceholder')" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item :label="t('log.traceId')">
          <el-input
            v-model="query.trace_id"
            :placeholder="t('log.traceIdPlaceholder')"
            clearable
            style="width: 220px"
          />
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button
          v-auth="'cccms:log:delete'"
          type="danger"
          plain
          :icon="Delete"
          :disabled="selection.length === 0"
          @click="onBatchDelete"
        >
          {{ selection.length ? t('log.deleteSelectedCount', { count: selection.length }) : t('log.deleteSelected') }}
        </el-button>
      </template>

      <template #toolbar-right>
        <el-button v-auth="'cccms:log:export'" :icon="Download" @click="onExport"> {{ t('common.export') }} </el-button>
      </template>

      <!-- 结果：成功 / 失败 -->
      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'danger'" effect="light" size="small">
          {{ row.status === 1 ? t('log.success') : t('log.failed') }}
        </el-tag>
      </template>

      <!-- 语义化操作：注解标题 + 权限节点 + 结果说明（登录失败原因等） -->
      <template #action_name="{ row }">
        <div class="log-title">{{ row.title || '—' }}</div>
        <div v-if="row.node" class="log-node">{{ row.node }}</div>
        <div v-if="row.message" class="log-msg">{{ row.message }}</div>
      </template>

      <template #method="{ row }">
        <el-tag effect="plain" size="small">{{ row.method }}</el-tag>
      </template>

      <template #status_code="{ row }">
        <el-tag :type="statusTag(row.status_code)" effect="light" size="small">
          {{ row.status_code }}
        </el-tag>
      </template>

      <!-- 链路ID：点一下看同一次请求的全部记录 -->
      <template #trace_id="{ row }">
        <el-button v-if="row.trace_id" link type="primary" @click="openTrace(row.trace_id)">
          {{ row.trace_id }}
        </el-button>
        <span v-else>—</span>
      </template>

      <template #action="{ row }">
        <el-button link type="primary" @click="openDetail(row)">{{ t('log.detail') }}</el-button>
        <el-button v-auth="'cccms:log:trace'" link type="primary" @click="openTrace(row.trace_id)">
          {{ t('log.sameTrace') }}
        </el-button>
        <el-button v-auth="'cccms:log:delete'" link type="danger" @click="onDeleteOne(row.id)">
          {{ t('common.delete') }}
        </el-button>
      </template>
    </ArtTable>

    <el-drawer v-model="detailVisible" :title="t('log.detailTitle')" size="680px">
      <template v-if="current">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item :label="t('log.result')">
            <el-tag :type="current.status === 1 ? 'success' : 'danger'" effect="light" size="small">
              {{ current.status === 1 ? t('log.success') : t('log.failed') }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item :label="t('log.actionName')">{{ current.title || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.node')">{{ current.node || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.username')">{{ current.username || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.time')">{{ current.create_time || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.request')">{{ current.method }} {{ current.path }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.statusCode')">
            <el-tag :type="statusTag(current.status_code)" effect="light" size="small">
              {{ current.status_code }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="IP">{{ current.ip || '—' }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.cost')">{{ current.cost ?? 0 }} ms</el-descriptions-item>
          <el-descriptions-item :label="t('log.traceId')" :span="2">
            <span class="log-trace">{{ current.trace_id || '—' }}</span>
            <el-button
              v-if="current.trace_id"
              v-auth="'cccms:log:trace'"
              link
              type="primary"
              class="log-trace-btn"
              @click="openTrace(current.trace_id)"
            >
              {{ t('log.viewSameTrace') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item v-if="current.message" :label="t('log.message')" :span="2">
            {{ current.message }}
          </el-descriptions-item>
          <el-descriptions-item label="User-Agent" :span="2">
            {{ current.ua || '—' }}
          </el-descriptions-item>
        </el-descriptions>

        <div class="log-block">
          <div class="log-block-title">{{ t('log.params') }}</div>
          <pre class="log-pre">{{ pretty(current.params) }}</pre>
        </div>

        <div class="log-block">
          <div class="log-block-title">{{ t('log.response') }}</div>
          <pre class="log-pre">{{ pretty(current.result) }}</pre>
        </div>
      </template>
    </el-drawer>

    <!-- 链路视图：一次请求的全部记录按时间升序 -->
    <el-drawer v-model="traceVisible" :title="t('log.traceTitle', { id: trace?.trace_id ?? '' })" size="720px">
      <template v-if="trace">
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item :label="t('log.recordCount')">{{ trace.total }}</el-descriptions-item>
          <el-descriptions-item :label="t('log.failedCount')">
            <el-tag :type="trace.failed > 0 ? 'danger' : 'success'" effect="light" size="small">
              {{ trace.failed }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item :label="t('log.totalCost')">{{ trace.cost }} ms</el-descriptions-item>
        </el-descriptions>

        <el-empty v-if="trace.total === 0" :description="t('log.traceEmpty')" />
        <el-timeline v-else class="trace-timeline">
          <el-timeline-item
            v-for="row in trace.list"
            :key="row.id"
            :timestamp="row.create_time"
            :type="row.status === 1 ? 'primary' : 'danger'"
            placement="top"
          >
            <div class="trace-item">
              <div class="trace-head">
                <el-tag effect="plain" size="small">{{ row.method }}</el-tag>
                <span class="trace-path">{{ row.path }}</span>
                <el-tag :type="statusTag(row.status_code)" effect="light" size="small">
                  {{ row.status_code }}
                </el-tag>
                <span class="trace-cost">{{ row.cost }} ms</span>
              </div>
              <div class="trace-meta">
                {{ row.title || '—' }}
                <span v-if="row.node"> · {{ row.node }}</span>
                · {{ row.username || '—' }}
              </div>
              <div v-if="row.message" class="trace-msg">{{ row.message }}</div>
            </div>
          </el-timeline-item>
        </el-timeline>
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:log' })

import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Download } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { logDelete, logExport, logList, logTrace, type LogRow, type LogTrace } from '@/api/log'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

interface Query {
  username: string
  title: string
  path: string
  trace_id: string
  /** 结果 1 成功 / 0 失败 */
  status: number | ''
}

/** 表头文案走 i18n：用 computed 包住，切换语言时能实时重渲染 */
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'title', label: t('table.action'), minWidth: 190, slot: 'action_name' },
  { prop: 'username', label: t('log.username'), width: 110 },
  { prop: 'status', label: t('log.result'), width: 90, align: 'center', slot: 'status' },
  { prop: 'method', label: t('log.method'), width: 90, align: 'center', slot: 'method' },
  { prop: 'path', label: t('log.path'), minWidth: 200 },
  { prop: 'trace_id', label: t('log.traceId'), width: 200, slot: 'trace_id' },
  { prop: 'ip', label: 'IP', width: 140, defaultHidden: true },
  { prop: 'status_code', label: t('log.statusCode'), width: 100, align: 'center', slot: 'status_code' },
  { prop: 'cost', label: t('log.costMs'), width: 100, align: 'right' },
  { prop: 'create_time', label: t('log.time'), width: 170 },
  { prop: 'action', label: t('table.action'), width: 190, fixed: 'right', slot: 'action', lockVisible: true },
])

/** 支持从报错弹窗直接跳进来：/log?trace_id=xxx */
const route = useRoute()
const initialTraceId = typeof route.query.trace_id === 'string' ? route.query.trace_id : ''

const {
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
} = useTable<LogRow, Query>({
  api: logList,
  initialQuery: { username: '', title: '', path: '', trace_id: initialTraceId, status: '' },
  pageSize: 15,
})

/* ---- 链路视图 ---- */
const traceVisible = ref(false)
const trace = ref<LogTrace | null>(null)

async function openTrace(traceId: string): Promise<void> {
  if (!traceId) {
    return
  }
  trace.value = await logTrace(traceId)
  traceVisible.value = true
}

onMounted(() => {
  // 带 trace_id 进来时直接展开链路，省去再点一次
  if (initialTraceId) {
    void openTrace(initialTraceId)
  }
})

function onExport(): void {
  void logExport({ ...query })
}

/* ---- 详情 ---- */
const detailVisible = ref(false)
const current = ref<LogRow | null>(null)

function openDetail(row: LogRow): void {
  current.value = row
  detailVisible.value = true
}

/** 参数/结果都按 JSON 存，能解析就格式化，否则原样显示（超长会被截断，JSON 不完整） */
function pretty(raw?: string): string {
  if (!raw) {
    return '—'
  }
  try {
    return JSON.stringify(JSON.parse(raw), null, 2)
  } catch {
    return raw
  }
}

function statusTag(code?: number): 'success' | 'warning' | 'danger' {
  if (!code) {
    return 'danger'
  }
  if (code < 300) {
    return 'success'
  }
  return code < 400 ? 'warning' : 'danger'
}

/* ---- 删除 ---- */
async function onBatchDelete(): Promise<void> {
  if (selection.value.length === 0) {
    return
  }
  try {
    await ElMessageBox.confirm(
      t('log.deleteSelectedConfirm', { count: selection.value.length }),
      t('log.batchDeleteTitle'),
      { type: 'warning' },
    )
  } catch {
    return
  }
  await logDelete(selection.value.map((row) => row.id))
  ElMessage.success(t('log.deleteSuccess'))
  load()
}

async function onDeleteOne(id: number): Promise<void> {
  try {
    await ElMessageBox.confirm(t('log.deleteOneConfirm'), t('common.delete'), { type: 'warning' })
  } catch {
    return
  }
  await logDelete([id])
  ElMessage.success(t('log.deleteSuccess'))
  load()
}
</script>

<style scoped>
.log-title {
  color: var(--art-main);
}

.log-node {
  margin-top: 1px;
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  color: var(--art-muted);
}

.log-msg {
  margin-top: 1px;
  font-size: 12px;
  color: var(--art-danger);
}

.log-trace {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  user-select: all;
}

.log-trace-btn {
  margin-left: 8px;
}

/* ---- 链路视图 ---- */
.trace-timeline {
  padding-left: 4px;
  margin-top: 18px;
}

.trace-item {
  padding: 8px 10px;
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 2px);
}

.trace-head {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.trace-path {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-main);
  word-break: break-all;
}

.trace-cost {
  margin-left: auto;
  font-size: 12px;
  color: var(--art-muted);
}

.trace-meta {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.trace-msg {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-danger);
}

.log-block {
  margin-top: 18px;
}

.log-block-title {
  padding-left: 8px;
  margin-bottom: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-left: 3px solid var(--el-color-primary);
}

.log-pre {
  max-height: 260px;
  padding: 12px;
  overflow: auto;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 2px);
}
</style>
