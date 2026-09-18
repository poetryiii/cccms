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
      @search="search"
      @reset="reset"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @restore="onRestore"
      @force-delete="onForceDelete"
    >
      <template #search>
        <el-form-item label="任务名称">
          <el-input v-model="query.name" placeholder="请输入" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="分组">
          <el-input v-model="query.group_name" placeholder="如 系统" clearable style="width: 140px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 130px">
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button v-auth="'cccms:crontab:save'" type="primary" :icon="Plus" @click="openCreate"> 新增任务 </el-button>
        <span class="toolbar-tip">调度进程仅跑在 Linux/macOS，Windows 可用「立即执行」验证</span>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" label="定时任务" @toggle="toggle" />
      </template>

      <template #expression="{ row }">
        <el-tag effect="plain" size="small">{{ row.expression }}</el-tag>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? '启用' : '停用' }}
        </el-tag>
        <el-tag v-if="row.running === 1" type="warning" effect="dark" size="small" class="status-extra">
          运行中
        </el-tag>
        <el-tag v-else-if="row.retry_left > 0" type="danger" effect="plain" size="small" class="status-extra">
          待重试({{ row.retry_left }})
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:crontab:run'" link type="primary" :loading="running === row.id" @click="onRun(row)">
          立即执行
        </el-button>
        <el-button v-auth="'cccms:crontab:logs'" link type="primary" @click="openLogs(row)">日志</el-button>
        <el-button v-auth="'cccms:crontab:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
        <el-popconfirm title="确定删除该任务？" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:crontab:delete'" link type="danger">删除</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 表单 -->
    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑定时任务' : '新增定时任务'"
      width="850px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="任务名称" prop="name">
          <el-input v-model="form.name" placeholder="如 清理历史日志" />
        </el-form-item>
        <el-form-item label="执行目标" prop="target">
          <el-select v-model="form.target" placeholder="仅可选择实现 CrontabTask 的类" style="width: 100%">
            <el-option v-for="t in targets" :key="t.class" :label="t.label" :value="t.class" />
          </el-select>
        </el-form-item>
        <el-form-item label="cron 表达式" prop="expression">
          <CronEditor v-model="form.expression" />
        </el-form-item>
        <el-form-item label="参数（JSON）" prop="params">
          <el-input
            v-model="form.params"
            type="textarea"
            :autosize="{ minRows: 2, maxRows: 5 }"
            placeholder='如 {"keep_days": 30}'
          />
        </el-form-item>
        <el-form-item label="任务分组" prop="group_name">
          <el-input v-model="form.group_name" placeholder="如 系统 / 报表（仅用于归类与筛选）" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="重叠策略" prop="overlap">
          <el-radio-group v-model="form.overlap">
            <el-radio value="skip">跳过（上次未结束则本次不跑）</el-radio>
            <el-radio value="allow">允许并发</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="超时(秒)" prop="timeout">
          <el-input-number v-model="form.timeout" :min="0" :max="86400" />
          <span class="form-tip">0 = 不限。超时会释放运行锁并记一条「超时释放」日志（无法强杀进程内代码）</span>
        </el-form-item>
        <el-form-item label="失败重试" prop="retry_times">
          <el-input-number v-model="form.retry_times" :min="0" :max="10" />
          <span class="form-tip">次，间隔</span>
          <el-input-number v-model="form.retry_interval" :min="1" :max="86400" />
          <span class="form-tip">秒</span>
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">确定</el-button>
      </template>
    </el-dialog>

    <!-- 执行日志 -->
    <el-drawer v-model="logVisible" :title="`执行日志 - ${currentTask.name}`" size="760px">
      <el-table v-loading="logLoading" :data="logs" row-key="id" stripe border max-height="480">
        <el-table-column prop="run_time" label="时间" width="170" />
        <el-table-column label="结果" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="logStatusMeta(row.status).type" effect="light" size="small">
              {{ logStatusMeta(row.status).label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="90" align="center">
          <template #default="{ row }">
            {{ sourceLabel(row.source) }}
          </template>
        </el-table-column>
        <el-table-column prop="cost" label="耗时(ms)" width="100" align="right" />
        <el-table-column prop="output" label="输出" min-width="200" show-overflow-tooltip />
      </el-table>

      <template #footer>
        <el-pagination
          :current-page="logPage"
          :page-size="logLimit"
          :total="logTotal"
          layout="total, prev, pager, next"
          background
          @current-change="onLogPageChange"
        />
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:crontab' })

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
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
  status?: number
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 70 },
  { prop: 'name', label: '任务名称', minWidth: 170 },
  { prop: 'group_name', label: '分组', width: 110 },
  { prop: 'expression', label: '表达式', width: 160, align: 'center', slot: 'expression' },
  { prop: 'target', label: '执行目标', minWidth: 210, defaultHidden: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', slot: 'status' },
  { prop: 'last_run_time', label: '上次执行', width: 170 },
  { prop: 'next_run_time', label: '下次执行', width: 170, defaultHidden: true },
  { prop: 'action', label: '操作', width: 260, fixed: 'right', slot: 'action', lockVisible: true },
]

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('crontab', {
  reload: () => search(),
})

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  CrontabRow,
  Query
>({
  api: (params) => crontabList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '', group_name: '', status: undefined },
})

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

const rules: FormRules = {
  name: [{ required: true, message: '请输入任务名称', trigger: 'blur' }],
  target: [{ required: true, message: '请选择执行目标', trigger: 'change' }],
  expression: [{ required: true, message: '请设置 cron 表达式', trigger: 'change' }],
}

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
      ElMessage.error('参数不是合法 JSON')
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
    ElMessage.success('保存成功')
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await crontabDelete(id)
  ElMessage.success('删除成功')
  load()
}

async function onRun(record: CrontabRow): Promise<void> {
  running.value = record.id
  try {
    const res = await crontabRun(record.id)
    if (res.status === 1) {
      ElMessage.success(`执行成功（${res.cost}ms）：${res.output}`)
    } else {
      ElMessage.error(`执行失败：${res.output}`)
    }
    load()
  } finally {
    running.value = 0
  }
}

/* ---- 日志 ---- */
const logVisible = ref(false)
const logLoading = ref(false)
const logs = ref<CrontabLogRow[]>([])
const logPage = ref(1)
const logLimit = ref(10)
const logTotal = ref(0)
const currentTask = reactive({ id: 0, name: '' })

function openLogs(record: CrontabRow): void {
  currentTask.id = record.id
  currentTask.name = record.name
  logPage.value = 1
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

/** 执行日志状态：1 成功 · 0 失败 · 2 跳过 · 3 超时释放 */
function logStatusMeta(status: number): { label: string; type: 'success' | 'danger' | 'info' | 'warning' } {
  switch (status) {
    case 1:
      return { label: '成功', type: 'success' }
    case 2:
      return { label: '跳过', type: 'info' }
    case 3:
      return { label: '超时释放', type: 'warning' }
    default:
      return { label: '失败', type: 'danger' }
  }
}

const SOURCE_LABELS: Record<string, string> = {
  cron: '调度',
  retry: '重试',
  manual: '手工',
  timeout: '超时',
}

function sourceLabel(source?: string): string {
  return SOURCE_LABELS[String(source ?? 'cron')] ?? String(source ?? '—')
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
</style>
