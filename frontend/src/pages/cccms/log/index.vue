<template>
  <div class="art-fill">
    <!-- 标签页：操作日志 / 登录分析（后者需要 cccms:log:analysis，无权限时不渲染入口） -->
    <div v-if="canAnalyse" class="log-tabs">
      <el-radio-group v-model="activeTab" size="small">
        <el-radio-button value="operation">{{ t('log.tabOperation') }}</el-radio-button>
        <el-radio-button value="login">{{ t('log.tabLogin') }}</el-radio-button>
      </el-radio-group>
    </div>

    <ArtTable
      v-show="activeTab === 'operation'"
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      selection
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @selection-change="onSelectionChange"
    >
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
        <el-button :icon="List" @click="openTasks">{{ t('log.exportTasks') }}</el-button>
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

    <!-- 登录安全分析（P2-13）：失败趋势 / TOP 账号 / TOP IP / 异地登录 -->
    <div v-if="canAnalyse && activeTab === 'login'" v-loading="analysisLoading" class="login-analysis">
      <div class="analysis-bar">
        <el-radio-group v-model="analysisDays" size="small">
          <el-radio-button :value="7">{{ t('log.analysisRange7') }}</el-radio-button>
          <el-radio-button :value="30">{{ t('log.analysisRange30') }}</el-radio-button>
          <el-radio-button :value="90">{{ t('log.analysisRange90') }}</el-radio-button>
        </el-radio-group>
        <el-button size="small" :icon="Refresh" @click="loadAnalysis">{{ t('log.analysisRefresh') }}</el-button>
      </div>

      <template v-if="analysis">
        <div class="analysis-cards">
          <div v-for="card in analysisCards" :key="card.label" class="analysis-card">
            <div class="analysis-card-value" :class="card.tone">{{ card.value }}</div>
            <div class="analysis-card-label">{{ card.label }}</div>
          </div>
        </div>

        <div class="analysis-panel">
          <div class="analysis-panel-title">{{ t('log.analysisTrend') }}</div>
          <div ref="trendRef" class="analysis-chart" />
        </div>

        <div class="analysis-grid">
          <div class="analysis-panel">
            <div class="analysis-panel-title">{{ t('log.analysisTopUsers') }}</div>
            <el-empty v-if="analysis.top_users.length === 0" :description="t('log.analysisEmpty')" :image-size="60" />
            <el-table v-else :data="analysis.top_users" size="small" :show-header="false">
              <el-table-column prop="username" />
              <el-table-column prop="count" width="80" align="right" />
            </el-table>
          </div>

          <div class="analysis-panel">
            <div class="analysis-panel-title">{{ t('log.analysisTopIps') }}</div>
            <el-empty v-if="analysis.top_ips.length === 0" :description="t('log.analysisEmpty')" :image-size="60" />
            <el-table v-else :data="analysis.top_ips" size="small" :show-header="false">
              <el-table-column prop="ip" />
              <el-table-column prop="count" width="80" align="right" />
            </el-table>
          </div>
        </div>

        <div class="analysis-panel">
          <div class="analysis-panel-title">{{ t('log.analysisIpChanges') }}</div>
          <el-empty v-if="analysis.ip_changes.length === 0" :description="t('log.analysisNoChange')" :image-size="60" />
          <el-table v-else :data="analysis.ip_changes" size="small">
            <el-table-column prop="username" :label="t('log.username')" width="160" />
            <el-table-column prop="from_ip" :label="t('log.analysisFromIp')" width="160" />
            <el-table-column prop="to_ip" :label="t('log.analysisToIp')" width="160" />
            <el-table-column prop="time" :label="t('log.analysisTime')" />
          </el-table>
        </div>

        <div class="analysis-hint">{{ t('log.analysisHint') }}</div>
      </template>
    </div>

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

    <!-- 导出任务中心：超阈值导出转后台生成，这里看状态并下载 -->
    <el-drawer v-model="taskVisible" :title="t('log.exportTasks')" size="520px">
      <el-button size="small" :icon="Refresh" :loading="taskLoading" @click="loadTasks">
        {{ t('log.analysisRefresh') }}
      </el-button>

      <el-empty v-if="tasks.length === 0 && !taskLoading" :description="t('log.taskEmpty')" :image-size="80" />
      <el-table v-else :data="tasks" size="small" class="task-table">
        <el-table-column :label="t('log.taskRows')" width="90" align="right">
          <template #default="{ row }">{{ row.total_rows }}</template>
        </el-table-column>
        <el-table-column :label="t('log.taskTime')" min-width="150">
          <template #default="{ row }">{{ row.create_time }}</template>
        </el-table-column>
        <el-table-column :label="t('log.taskStatusLabel')" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="taskStatusTag(row.status)" size="small">{{ taskStatusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="t('table.action')" width="90" align="center">
          <template #default="{ row }">
            <el-button v-if="row.status === 2" link type="primary" :icon="Download" @click="downloadTask(row)">
              {{ t('common.export') }}
            </el-button>
            <span v-else-if="row.status === 3" class="task-error" :title="row.error">{{ row.error }}</span>
          </template>
        </el-table-column>
      </el-table>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:log' })

import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Download, List, Refresh } from '@element-plus/icons-vue'
import * as echarts from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'
import { logDelete, logExport, logList, logLoginAnalysis, logTrace, type LogRow, type LogTrace } from '@/api/log'
import { exportTaskDownload, exportTaskList, type ExportTaskRow } from '@/api/export'
import type { ArtTableColumn } from '@/types/table'

// 按需引入：与工作台同一取舍（只用到折线图，不必整包）
echarts.use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer])

const { t, locale } = useI18n({ useScope: 'global' })

interface Query {
  username: string
  title: string
  path: string
  trace_id: string
  /** 结果 1 成功 / 0 失败，多选值以逗号串传递 */
  status: string
  /** 请求方法，多选值以逗号串传递 */
  method: string
  ip: string
  /** 操作时间范围（列头日期筛选写入） */
  start: string
  end: string
}

/** 请求方法枚举：接口日志里出现过的几种（GET 查询 / POST 新增 / PUT 更新 / DELETE 删除） */
const METHOD_OPTIONS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'].map((method) => ({
  label: method,
  value: method,
}))

/** 表头文案走 i18n：用 computed 包住，切换语言时能实时重渲染 */
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'title', label: t('table.action'), minWidth: 190, slot: 'action_name', filter: { type: 'text' } },
  { prop: 'username', label: t('log.username'), width: 110, filter: { type: 'text' } },
  {
    prop: 'status',
    label: t('log.result'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('log.success'), value: 1 },
        { label: t('log.failed'), value: 0 },
      ],
    },
  },
  {
    prop: 'method',
    label: t('log.method'),
    width: 90,
    align: 'center',
    slot: 'method',
    filter: { type: 'enum', options: METHOD_OPTIONS },
  },
  { prop: 'path', label: t('log.path'), minWidth: 200, filter: { type: 'text' } },
  { prop: 'trace_id', label: t('log.traceId'), width: 240, slot: 'trace_id', filter: { type: 'text' } },
  { prop: 'ip', label: 'IP', width: 140, filter: { type: 'text' } },
  { prop: 'status_code', label: t('log.statusCode'), width: 100, align: 'center', slot: 'status_code' },
  { prop: 'cost', label: t('log.costMs'), width: 100, align: 'right' },
  { prop: 'create_time', label: t('log.time'), width: 170, filter: { type: 'date' } },
  { prop: 'action', label: t('table.action'), width: 160, fixed: 'right', slot: 'action', lockVisible: true },
])

/** 支持从报错弹窗直接跳进来：/log?trace_id=xxx */
const route = useRoute()
const initialTraceId = typeof route.query.trace_id === 'string' ? route.query.trace_id : ''

const { list, loading, total, page, limit, query, selection, load, onPageChange, onLimitChange, onSelectionChange } =
  useTable<LogRow, Query>({
    api: logList,
    initialQuery: {
      username: '',
      title: '',
      path: '',
      trace_id: initialTraceId,
      status: '',
      method: '',
      ip: '',
      start: '',
      end: '',
    },
    pageSize: 15,
  })

/* ---- 登录分析（P2-13） ---- */
const activeTab = ref<'operation' | 'login'>('operation')
const analysisDays = ref(7)
const analysis = ref<Awaited<ReturnType<typeof logLoginAnalysis>> | null>(null)
const analysisLoading = ref(false)
const trendRef = ref<HTMLElement>()
let trendChart: echarts.ECharts | null = null

const setting = useSettingStore()
const userStore = useUserStore()
/** 没有分析权限时不渲染入口，避免「点进去 403」 */
const canAnalyse = computed(() => userStore.hasAuth('cccms:log:analysis'))

const analysisCards = computed(() => {
  const s = analysis.value?.summary
  if (!s) {
    return []
  }

  return [
    { label: t('log.analysisTotal'), value: String(s.total), tone: '' },
    { label: t('log.analysisSuccess'), value: String(s.success), tone: 'is-ok' },
    { label: t('log.analysisFailed'), value: String(s.failed), tone: s.failed > 0 ? 'is-danger' : '' },
    { label: t('log.analysisFailRate'), value: `${s.fail_rate}%`, tone: s.fail_rate > 0 ? 'is-danger' : '' },
    { label: t('log.analysisFailUsers'), value: String(s.users), tone: '' },
    { label: t('log.analysisFailIps'), value: String(s.ips), tone: '' },
  ]
})

async function loadAnalysis(): Promise<void> {
  if (!canAnalyse.value) {
    return
  }

  analysisLoading.value = true
  try {
    analysis.value = await logLoginAnalysis({ days: analysisDays.value })
    await nextTick()
    renderTrend()
  } finally {
    analysisLoading.value = false
  }
}

/** 主题色 / 文字色取自 CSS 变量，与全局主题保持一致 */
function cssVar(name: string, fallback: string): string {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

function renderTrend(): void {
  if (!trendRef.value || !analysis.value) {
    return
  }
  trendChart ??= echarts.init(trendRef.value)

  const primary = cssVar('--art-primary', '#2b6cff')
  const danger = cssVar('--art-danger', '#e34d59')
  const line = cssVar('--art-card-border', '#e9edf5')
  const sub = cssVar('--art-sub', '#5b6474')

  const hourly = analysis.value.range.granularity === 'hour'
  // 小时粒度只展示「时:分」，天粒度展示「月-日」，否则 X 轴会被年份挤满
  const labels = analysis.value.trend.map((item) => (hourly ? item.bucket.slice(11, 16) : item.bucket.slice(5, 10)))

  trendChart.setOption({
    grid: { left: 6, right: 18, top: 32, bottom: 4, containLabel: true },
    tooltip: { trigger: 'axis' },
    legend: { top: 0, right: 0, textStyle: { color: sub } },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: labels,
      axisLine: { lineStyle: { color: line } },
      axisTick: { show: false },
      axisLabel: { color: sub },
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      splitLine: { lineStyle: { color: line, type: 'dashed' } },
      axisLabel: { color: sub },
    },
    series: [
      {
        name: t('log.analysisTrendSuccess'),
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 6,
        data: analysis.value.trend.map((item) => item.success),
        itemStyle: { color: primary },
        lineStyle: { width: 2, color: primary },
      },
      {
        name: t('log.analysisTrendFailed'),
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 6,
        data: analysis.value.trend.map((item) => item.failed),
        itemStyle: { color: danger },
        lineStyle: { width: 2, color: danger },
      },
    ],
  })
}

// 首次切到分析页才请求（省掉无谓的聚合查询）
watch(activeTab, (tab) => {
  if (tab === 'login' && analysis.value === null) {
    void loadAnalysis()
  }
})
watch(analysisDays, () => {
  if (activeTab.value === 'login') {
    void loadAnalysis()
  }
})
// 主题 / 语言变化后重绘：echarts 是命令式渲染，不会跟着响应式更新
watch([() => setting.isDark, locale], async () => {
  await nextTick()
  renderTrend()
})

function onResize(): void {
  trendChart?.resize()
}

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
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  // 组件销毁时手动释放：echarts 实例不挂在 DOM 上，不 dispose 会随路由切换堆积
  trendChart?.dispose()
  trendChart = null
})

async function onExport(): Promise<void> {
  const result = await logExport({ ...query })
  // 超阈值：后端已转后台任务，打开任务中心看进度
  if (result?.async) {
    ElMessage.info(t('log.exportQueued', { total: result.total }))
    await openTasks()
  }
}

/* ---- 导出任务中心 ---- */
const taskVisible = ref(false)
const taskLoading = ref(false)
const tasks = ref<ExportTaskRow[]>([])

async function loadTasks(): Promise<void> {
  taskLoading.value = true
  try {
    tasks.value = await exportTaskList()
  } finally {
    taskLoading.value = false
  }
}

async function openTasks(): Promise<void> {
  taskVisible.value = true
  await loadTasks()
}

function taskStatusText(status: number): string {
  const map: Record<number, string> = {
    0: t('log.taskStatusPending'),
    1: t('log.taskStatusRunning'),
    2: t('log.taskStatusDone'),
    3: t('log.taskStatusFailed'),
    4: t('log.taskStatusExpired'),
  }

  return map[status] ?? String(status)
}

function taskStatusTag(status: number): 'info' | 'warning' | 'success' | 'danger' {
  const map: Record<number, 'info' | 'warning' | 'success' | 'danger'> = {
    0: 'info',
    1: 'warning',
    2: 'success',
    3: 'danger',
    4: 'info',
  }

  return map[status] ?? 'info'
}

function downloadTask(row: ExportTaskRow): void {
  void exportTaskDownload(row.id)
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
/* ---- 标签页 ---- */
.log-tabs {
  flex: none;
  margin-bottom: 12px;
}

/* ---- 登录分析 ---- */
.login-analysis {
  flex: 1;
  min-height: 0;
  padding-right: 2px;
  overflow: auto;
}

.analysis-bar {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.analysis-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.analysis-card {
  padding: 14px 16px;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
}

.analysis-card-value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--art-main);
}

.analysis-card-value.is-ok {
  color: var(--art-success);
}

.analysis-card-value.is-danger {
  color: var(--art-danger);
}

.analysis-card-label {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.analysis-panel {
  padding: 14px 16px;
  margin-bottom: 12px;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
}

.analysis-panel-title {
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
}

.analysis-chart {
  height: 260px;
}

.analysis-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 12px;
}

.analysis-hint {
  padding-bottom: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

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
