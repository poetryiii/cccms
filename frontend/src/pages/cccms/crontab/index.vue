<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      :recycle="recycle"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @restore="onRestore"
      @force-delete="onForceDelete"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:crontab:save'" type="primary" @click="openCreate"
          ><template #icon><i class="ri-add-line" /></template>
          {{ t('crontab.create') }}
        </el-button>
        <span class="toolbar-tip">{{ t('crontab.toolbarTip') }}</span>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('crontab.recycleLabel')" @toggle="toggle" />
      </template>

      <template #group_name="{ row }">
        <el-tooltip v-if="row.group_name" :content="groupTip(row.group_name)" placement="top">
          <span
            class="group-cell"
            :class="{ 'is-active': query.group_name === row.group_name }"
            @click="onGroupClick(row.group_name)"
            >{{ row.group_name }}</span
          >
        </el-tooltip>
        <span v-else class="group-empty">—</span>
      </template>

      <template #expression="{ row }">
        <el-tag effect="plain" size="small">{{ row.expression }}</el-tag>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('crontab.enabled') : t('crontab.disabled') }}
        </el-tag>
        <el-tag v-if="row.running === 1" type="warning" effect="dark" size="small" class="status-extra">
          {{ t('crontab.running') }}
        </el-tag>
        <el-tag v-else-if="row.retry_left > 0" type="danger" effect="plain" size="small" class="status-extra">
          {{ t('crontab.retryPending', { count: row.retry_left }) }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:crontab:run'" link type="primary" :loading="running === row.id" @click="onRun(row)">
          {{ t('crontab.runNow') }}
        </el-button>
        <el-button v-auth="'cccms:crontab:logs'" link type="primary" @click="openLogs(row)">
          {{ t('crontab.logs') }}
        </el-button>
        <el-button v-auth="'cccms:crontab:update'" link type="primary" @click="openEdit(row)">
          {{ t('common.edit') }}
        </el-button>
        <el-popconfirm :title="t('crontab.deleteConfirm')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:crontab:delete'" link type="danger">{{ t('common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 表单 -->
    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('crontab.editTitle') : t('crontab.createTitle')"
      width="850px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item :label="t('crontab.nameLabel')" prop="name">
          <el-input v-model="form.name" :placeholder="t('crontab.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('crontab.targetLabel')" prop="target">
          <el-select v-model="form.target" :placeholder="t('crontab.targetPlaceholder')" style="width: 100%">
            <el-option v-for="item in targets" :key="item.class" :label="item.label" :value="item.class" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('crontab.expressionLabel')" prop="expression">
          <CronEditor v-model="form.expression" />
        </el-form-item>
        <el-form-item :label="t('crontab.paramsLabel')" prop="params">
          <el-input
            v-model="form.params"
            type="textarea"
            :autosize="{ minRows: 2, maxRows: 5 }"
            :placeholder="t('crontab.paramsPlaceholder')"
          />
        </el-form-item>
        <el-form-item :label="t('crontab.groupFormLabel')" prop="group_name">
          <el-input v-model="form.group_name" :placeholder="t('crontab.groupFormPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('crontab.statusLabel')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('crontab.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('crontab.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('crontab.overlapLabel')" prop="overlap">
          <el-radio-group v-model="form.overlap">
            <el-radio value="skip">{{ t('crontab.overlapSkip') }}</el-radio>
            <el-radio value="allow">{{ t('crontab.overlapAllow') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('crontab.timeoutLabel')" prop="timeout">
          <el-input-number v-model="form.timeout" :min="0" :max="86400" />
          <span class="form-tip">{{ t('crontab.timeoutTip') }}</span>
        </el-form-item>
        <el-form-item :label="t('crontab.retryLabel')" prop="retry_times">
          <el-input-number v-model="form.retry_times" :min="0" :max="10" />
          <span class="form-tip">{{ t('crontab.retryTimesSuffix') }}</span>
          <el-input-number v-model="form.retry_interval" :min="1" :max="86400" />
          <span class="form-tip">{{ t('crontab.retrySecondsSuffix') }}</span>
        </el-form-item>
        <el-form-item :label="t('crontab.remarkLabel')" prop="remark">
          <el-input v-model="form.remark" :placeholder="t('crontab.remarkPlaceholder')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 执行日志 -->
    <el-drawer v-model="logVisible" :title="t('crontab.logsTitle', { name: currentTask.name })" size="880px">
      <!-- 按列表页表格的方式组织：工具条 + 撑满的表格 + 底部固定分页（而不是塞进 drawer footer） -->
      <div class="log-panel">
        <div class="log-toolbar">
          <el-select
            v-model="logStatus"
            clearable
            :placeholder="t('crontab.allPlaceholder')"
            style="width: 140px"
            @change="onLogFilterChange"
          >
            <el-option :label="t('crontab.logSuccess')" :value="1" />
            <el-option :label="t('crontab.logFailed')" :value="0" />
            <el-option :label="t('crontab.logSkipped')" :value="2" />
            <el-option :label="t('crontab.logTimeout')" :value="3" />
          </el-select>

          <el-button text circle :title="t('table.refresh')" @click="loadLogs">
            <i class="ri-refresh-line" />
          </el-button>
        </div>

        <div class="log-table-wrap">
          <el-table v-loading="logLoading" :data="logs" row-key="id" stripe border height="100%">
            <el-table-column prop="run_time" :label="t('crontab.logTime')" width="170" />
            <el-table-column :label="t('crontab.logResult')" width="100" align="center">
              <template #default="{ row }">
                <el-tag :type="logStatusMeta(row.status).type" effect="light" size="small">
                  {{ t(logStatusMeta(row.status).labelKey) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column :label="t('crontab.logSource')" width="90" align="center">
              <template #default="{ row }">
                {{ sourceLabel(row.source) }}
              </template>
            </el-table-column>
            <el-table-column prop="cost" :label="t('crontab.logCost')" width="100" align="right" />
            <el-table-column prop="output" :label="t('crontab.logOutput')" min-width="200" show-overflow-tooltip />
          </el-table>
        </div>

        <div class="log-pager">
          <el-pagination
            :current-page="logPage"
            :page-size="logLimit"
            :total="logTotal"
            :page-sizes="LOG_PAGE_SIZES"
            :layout="logPagerLayout"
            background
            @current-change="onLogPageChange"
            @size-change="onLogLimitChange"
          />
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:crontab' })

import { computed, onMounted, reactive, ref } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import CronEditor from '@/components/core/CronEditor.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import {
  crontabDelete,
  crontabList,
  crontabLogs,
  crontabRun,
  crontabSave,
  crontabTargets,
  crontabUpdate,
  type CrontabLogRow,
  type CrontabRow,
  type TaskTarget,
} from '@/api/crontab'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  name: string
  group_name: string
  /** 多选值以逗号串传递 */
  status: string
  /** 最近执行时间范围（列头日期筛选写入） */
  start: string
  end: string
  /** 下次执行时间范围（列头日期筛选写入） */
  next_start: string
  next_end: string
}

const { t } = useI18n({ useScope: 'global' })

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 70 },
  { prop: 'name', label: t('crontab.nameLabel'), minWidth: 170, filter: { type: 'text' } },
  // 分组列的自定义插槽：分组名可点击，点一下筛选该分组，再点取消筛选
  { prop: 'group_name', label: t('crontab.groupLabel'), width: 130, slot: 'group_name', filter: { type: 'text' } },
  { prop: 'expression', label: t('crontab.expressionColumnLabel'), width: 160, align: 'center', slot: 'expression' },
  { prop: 'target', label: t('crontab.targetColumnLabel'), minWidth: 210 },
  {
    prop: 'status',
    label: t('crontab.statusLabel'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('crontab.enabled'), value: 1 },
        { label: t('crontab.disabled'), value: 0 },
      ],
    },
  },
  { prop: 'last_run_time', label: t('crontab.lastRunLabel'), width: 170, filter: { type: 'date' } },
  {
    prop: 'next_run_time',
    label: t('crontab.nextRunLabel'),
    width: 170,
    filter: { type: 'date', startKey: 'next_start', endKey: 'next_end' },
  },
  { prop: 'action', label: t('table.action'), width: 260, fixed: 'right', slot: 'action', lockVisible: true },
])

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('crontab', {
  reload: () => load(),
})

const { list, loading, total, page, limit, query, search, load, onPageChange, onLimitChange } = useTable<
  CrontabRow,
  Query
>({
  api: (params) => crontabList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '', group_name: '', status: '', start: '', end: '', next_start: '', next_end: '' },
})

/** 分组名点击：未筛选该分组则筛选，已筛选则取消（与顶部「分组」搜索框共用同一个查询条件，天然联动） */
function onGroupClick(group: string): void {
  if (!group) {
    return
  }
  query.group_name = query.group_name === group ? '' : group
  search()
}

/** 分组名悬浮提示：按当前是否已筛选该分组给出不同文案 */
function groupTip(group: string): string {
  return query.group_name === group ? t('crontab.groupFilterClearTip') : t('crontab.groupFilterTip')
}

const targets = ref<TaskTarget[]>([])
const running = ref(0)
const saving = ref(false)

/* ---- 表单 ---- */
const formRef = ref<FormInstance>()
const formVisible = ref(false)
const emptyForm = {
  id: 0,
  name: '',
  group_name: '',
  target: '',
  // 6 段：每天 2 点整（编辑器始终生成 6 段，秒=0 即整分钟触发）
  expression: '0 0 2 * * *',
  params: '',
  status: 1,
  overlap: 'skip',
  timeout: 0,
  retry_times: 0,
  retry_interval: 60,
  remark: '',
}
const form = reactive<Record<string, any>>({ ...emptyForm })

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('crontab.nameRequired'), trigger: 'blur' }],
  target: [{ required: true, message: t('crontab.targetRequired'), trigger: 'change' }],
  expression: [{ required: true, message: t('crontab.expressionRequired'), trigger: 'change' }],
}))

function openCreate(): void {
  Object.assign(form, emptyForm)
  formVisible.value = true
}

function openEdit(record: CrontabRow): void {
  Object.assign(form, emptyForm, record)
  const params = record.params
  form.params = params && typeof params === 'object' ? JSON.stringify(params) : String(params ?? '')
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  let params: unknown = {}
  if (form.params && String(form.params).trim() !== '') {
    try {
      params = JSON.parse(form.params)
    } catch {
      ElMessage.error(t('crontab.invalidParams'))
      return
    }
  }

  saving.value = true
  try {
    const payload = { ...form, params }
    if (form.id) {
      await crontabUpdate(payload)
    } else {
      await crontabSave(payload)
    }
    ElMessage.success(t('crontab.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await crontabDelete(id)
  ElMessage.success(t('crontab.deleteSuccess'))
  load()
}

async function onRun(record: CrontabRow): Promise<void> {
  running.value = record.id
  try {
    const res = await crontabRun(record.id)
    if (res.status === 1) {
      ElMessage.success(t('crontab.runSuccess', { cost: res.cost, output: res.output }))
    } else {
      ElMessage.error(t('crontab.runFailed', { output: res.output }))
    }
    load()
  } finally {
    running.value = 0
  }
}

/* ---- 执行日志 ---- */
/** 与列表页表格一致的可选每页条数 */
const LOG_PAGE_SIZES = [10, 15, 30, 50, 100]

const logVisible = ref(false)
const logLoading = ref(false)
const logs = ref<CrontabLogRow[]>([])
const logPage = ref(1)
const logLimit = ref(10)
const logTotal = ref(0)
/** 结果筛选：'' = 全部 */
const logStatus = ref<number | ''>('')
const currentTask = reactive({ id: 0, name: '' })

/** 窄屏收起「每页条数 / 跳页」（与 ArtTable 的分页器口径一致） */
const isNarrow = useMediaQuery('(max-width: 768px)')
const logPagerLayout = computed(() =>
  isNarrow.value ? 'prev, pager, next' : 'total, sizes, prev, pager, next, jumper',
)

function openLogs(record: CrontabRow): void {
  currentTask.id = record.id
  currentTask.name = record.name
  logPage.value = 1
  logStatus.value = ''
  logVisible.value = true
  void loadLogs()
}

async function loadLogs(): Promise<void> {
  logLoading.value = true
  try {
    const res = await crontabLogs({
      crontab_id: currentTask.id,
      page: logPage.value,
      limit: logLimit.value,
      ...(logStatus.value === '' ? {} : { status: logStatus.value }),
    })
    logs.value = res.list
    logTotal.value = res.total
  } finally {
    logLoading.value = false
  }
}

function onLogPageChange(value: number): void {
  logPage.value = value
  void loadLogs()
}

/** 改每页条数：回到第 1 页重新拉（否则可能落在空白页） */
function onLogLimitChange(value: number): void {
  logLimit.value = value
  logPage.value = 1
  void loadLogs()
}

/** 切换结果筛选：回到第 1 页重新拉 */
function onLogFilterChange(): void {
  logPage.value = 1
  void loadLogs()
}

/** 执行日志状态：1 成功 · 0 失败 · 2 跳过 · 3 超时释放（labelKey 在模板里翻译） */
function logStatusMeta(status: number): { labelKey: string; type: 'success' | 'danger' | 'info' | 'warning' } {
  switch (status) {
    case 1:
      return { labelKey: 'crontab.logSuccess', type: 'success' }
    case 2:
      return { labelKey: 'crontab.logSkipped', type: 'info' }
    case 3:
      return { labelKey: 'crontab.logTimeout', type: 'warning' }
    default:
      return { labelKey: 'crontab.logFailed', type: 'danger' }
  }
}

const SOURCE_LABEL_KEYS: Record<string, string> = {
  cron: 'crontab.sourceCron',
  retry: 'crontab.sourceRetry',
  manual: 'crontab.sourceManual',
  timeout: 'crontab.sourceTimeout',
}

function sourceLabel(source?: string): string {
  const key = SOURCE_LABEL_KEYS[String(source ?? 'cron')]
  return key ? t(key) : String(source ?? '—')
}

onMounted(async () => {
  targets.value = await crontabTargets()
})
</script>

<style scoped>
.toolbar-tip {
  font-size: 12px;
  color: var(--art-muted);
}

.status-extra {
  margin-left: 4px;
}

.form-tip {
  margin-left: 8px;
  font-size: 12px;
  color: var(--art-muted);
}

/* ---- 分组列：分组名可点击筛选 ---- */
.group-cell {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  color: var(--el-color-primary);
  cursor: pointer;
  border-radius: 4px;
  transition:
    background-color 0.2s,
    color 0.2s;
}

.group-cell:hover {
  background-color: var(--el-color-primary-light-9);
}

/* 已按该分组筛选：实心高亮，一眼看出当前生效的筛选 */
.group-cell.is-active {
  color: #fff;
  background-color: var(--el-color-primary);
}

.group-empty {
  color: var(--art-muted);
}

/* ---- 执行日志抽屉：工具条 + 撑满表格 + 底部固定分页 ---- */
.log-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.log-toolbar {
  display: flex;
  flex-shrink: 0;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.log-table-wrap {
  flex: 1;
  min-height: 0;
}

.log-pager {
  display: flex;
  flex-shrink: 0;
  justify-content: flex-end;
  padding-top: 12px;
}
</style>
