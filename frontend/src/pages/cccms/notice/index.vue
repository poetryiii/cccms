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
        <el-form-item label="标题">
          <el-input v-model="query.title" placeholder="请输入" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="query.type" placeholder="全部" clearable style="width: 110px">
            <el-option label="通知" :value="1" />
            <el-option label="公告" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="已发布" :value="1" />
            <el-option label="草稿" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="投放">
          <el-select v-model="query.scope" placeholder="全部" clearable style="width: 130px">
            <el-option label="全部用户" :value="0" />
            <el-option label="指定部门" :value="1" />
            <el-option label="指定角色" :value="2" />
            <el-option label="指定用户" :value="3" />
          </el-select>
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button v-auth="'cccms:notice:save'" type="primary" :icon="Plus" @click="openCreate"> 新增 </el-button>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" label="通知公告" @toggle="toggle" />
      </template>

      <template #type="{ row }">
        <el-tag :type="row.type === 2 ? 'primary' : 'info'" effect="plain">
          {{ row.type === 2 ? '公告' : '通知' }}
        </el-tag>
      </template>

      <template #level="{ row }">
        <el-tag :type="row.level === 2 ? 'danger' : 'info'" effect="light">
          {{ row.level === 2 ? '重要' : '普通' }}
        </el-tag>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'warning'" effect="light" round>
          {{ row.status === 1 ? '已发布' : '草稿' }}
        </el-tag>
      </template>

      <template #scope="{ row }">
        <el-tag :type="scopeTagType(row.scope)" effect="plain" size="small">
          {{ scopeLabel(row.scope) }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:notice:report'" link type="success" @click="openReport(row)">回执</el-button>
        <el-button v-auth="'cccms:notice:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
        <el-popconfirm title="确定删除该通知公告？" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:notice:delete'" link type="danger">删除</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑通知公告' : '新增通知公告'"
      width="720px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="标题" prop="title">
          <el-input v-model="form.title" placeholder="请输入标题" maxlength="128" show-word-limit />
        </el-form-item>
        <el-form-item label="类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">通知</el-radio>
            <el-radio :value="2">公告</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="级别" prop="level">
          <el-radio-group v-model="form.level">
            <el-radio :value="1">普通</el-radio>
            <el-radio :value="2">重要</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">发布</el-radio>
            <el-radio :value="0">草稿</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="投放范围" prop="scope">
          <el-radio-group v-model="form.scope" @change="onScopeChange">
            <el-radio :value="0">全部用户</el-radio>
            <el-radio :value="1">指定部门</el-radio>
            <el-radio :value="2">指定角色</el-radio>
            <el-radio :value="3">指定用户</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.scope === 1" label="选择部门">
          <ArtNodePicker v-model="form.target_ids" :data="options.depts" multiple />
          <div class="form-tip">投放给所选部门（<b>含下级</b>）下的所有用户。</div>
        </el-form-item>
        <el-form-item v-if="form.scope === 2" label="选择角色">
          <ArtNodePicker v-model="form.target_ids" :data="options.roles" multiple />
          <div class="form-tip">投放给所选角色（<b>含后代角色</b>）下的所有用户。</div>
        </el-form-item>
        <el-form-item v-if="form.scope === 3" label="选择用户">
          <el-select
            v-model="form.target_ids"
            multiple
            filterable
            remote
            reserve-keyword
            :remote-method="searchUsers"
            :loading="userLoading"
            placeholder="输入账号/昵称搜索"
            style="width: 100%"
          >
            <el-option
              v-for="u in userOptions"
              :key="u.id"
              :label="u.nickname ? `${u.username}（${u.nickname}）` : u.username"
              :value="u.id"
            />
          </el-select>
          <div class="form-tip">投放给所选用户；输入账号/昵称模糊搜索。</div>
        </el-form-item>
        <el-form-item label="发布时间">
          <el-date-picker
            v-model="form.publish_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="留空则立即发布"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker
            v-model="form.expire_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="留空则不过期"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="正文" prop="content">
          <el-input v-model="form.content" type="textarea" :rows="8" placeholder="请输入正文" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">确定</el-button>
      </template>
    </el-dialog>

    <!-- 已读回执统计报表 -->
    <el-dialog
      v-model="reportVisible"
      :title="`已读回执：${report.notice.title}`"
      width="780px"
      :close-on-click-modal="false"
    >
      <div class="report-summary">
        <el-tag type="info" effect="plain">应读 {{ report.total }} 人</el-tag>
        <el-tag type="success" effect="plain">已读 {{ report.read }} 人</el-tag>
        <el-tag type="warning" effect="plain">未读 {{ report.unread }} 人</el-tag>
        <el-radio-group v-model="reportView" size="small" class="report-switch" @change="onReportViewChange">
          <el-radio-button value="read">已读明细</el-radio-button>
          <el-radio-button value="unread">未读明细</el-radio-button>
        </el-radio-group>
      </div>
      <el-table v-loading="reportLoading" :data="report.list" size="small" border>
        <el-table-column prop="user_id" label="用户ID" width="90" />
        <el-table-column prop="username" label="账号" min-width="120" />
        <el-table-column prop="nickname" label="昵称" min-width="120" />
        <el-table-column prop="read_time" label="已读时间" min-width="170">
          <template #default="{ row }">{{ row.read_time || '—' }}</template>
        </el-table-column>
      </el-table>
      <div class="report-pager">
        <el-pagination
          layout="total, prev, pager, next"
          :total="report.total"
          :page-size="reportLimit"
          :current-page="reportPage"
          @current-change="onReportPageChange"
        />
      </div>
      <template #footer>
        <el-button @click="reportVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:notice' })

import { reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import ArtNodePicker from '@/components/core/ArtNodePicker.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import {
  noticeDelete,
  noticeList,
  noticeOptions,
  noticeRead,
  noticeReport,
  noticeSave,
  noticeUpdate,
  noticeUsers,
  type NoticeOptions,
  type NoticeReport,
  type NoticeRow,
  type NoticeUserOption,
} from '@/api/notice'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  title: string
  type: number | ''
  status: number | ''
  scope: number | ''
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'title', label: '标题', minWidth: 220 },
  { prop: 'type', label: '类型', width: 90, align: 'center', slot: 'type' },
  { prop: 'level', label: '级别', width: 90, align: 'center', slot: 'level' },
  { prop: 'status', label: '状态', width: 100, align: 'center', slot: 'status' },
  { prop: 'scope', label: '投放范围', width: 110, align: 'center', slot: 'scope' },
  { prop: 'publish_at', label: '发布时间', width: 170 },
  { prop: 'expire_at', label: '过期时间', width: 170, defaultHidden: true },
  { prop: 'read_count', label: '已读人数', width: 100, align: 'right' },
  { prop: 'create_time', label: '创建时间', width: 170, defaultHidden: true },
  { prop: 'action', label: '操作', width: 170, fixed: 'right', slot: 'action', lockVisible: true },
]

const SCOPE_LABELS: Record<number, string> = { 0: '全部用户', 1: '指定部门', 2: '指定角色', 3: '指定用户' }
function scopeLabel(scope: number): string {
  return SCOPE_LABELS[scope] ?? '全部用户'
}
function scopeTagType(scope: number): 'info' | 'primary' | 'success' | 'warning' | 'danger' {
  return scope === 0 ? 'info' : 'primary'
}

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('notice', {
  reload: () => search(),
})

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  NoticeRow,
  Query
>({
  api: (params) => noticeList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { title: '', type: '', status: '', scope: '' },
})

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  title: '',
  type: 1,
  level: 1,
  status: 1,
  scope: 0,
  target_ids: [] as number[],
  publish_at: null as string | null,
  expire_at: null as string | null,
  content: '',
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules: FormRules = {
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
}

// ---- 投放目标候选 ----
const options = reactive<NoticeOptions>({ depts: [], roles: [] })

async function loadOptions(): Promise<void> {
  const data = await noticeOptions()
  options.depts = data.depts ?? []
  options.roles = data.roles ?? []
}

// 用户候选（懒加载）
const userOptions = ref<NoticeUserOption[]>([])
const userLoading = ref(false)

async function searchUsers(keyword: string): Promise<void> {
  userLoading.value = true
  try {
    userOptions.value = await noticeUsers({ keyword })
  } finally {
    userLoading.value = false
  }
}

async function loadSelectedUsers(ids: number[]): Promise<void> {
  if (!ids.length) {
    return
  }
  userOptions.value = await noticeUsers({ ids })
}

function onScopeChange(): void {
  form.target_ids = []
  userOptions.value = []
}

function openCreate(): void {
  Object.assign(form, emptyForm, { target_ids: [] })
  userOptions.value = []
  formVisible.value = true
}

async function openEdit(row: NoticeRow): Promise<void> {
  const detail = await noticeRead(row.id)
  Object.assign(form, emptyForm, {
    id: detail.id,
    title: detail.title,
    type: detail.type,
    level: detail.level,
    status: detail.status,
    scope: detail.scope ?? 0,
    target_ids: Array.isArray(detail.target_ids) ? [...detail.target_ids] : [],
    publish_at: detail.publish_at,
    expire_at: detail.expire_at,
    content: detail.content,
  })
  userOptions.value = []
  if ((detail.scope ?? 0) === 3) {
    await loadSelectedUsers(form.target_ids)
  }
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (form.scope !== 0 && (!Array.isArray(form.target_ids) || form.target_ids.length === 0)) {
    ElMessage.warning('请选择投放目标')
    return
  }
  saving.value = true
  try {
    const payload = { ...form, target_ids: form.scope === 0 ? [] : form.target_ids }
    if (form.id) {
      await noticeUpdate(payload)
    } else {
      await noticeSave(payload)
    }
    ElMessage.success('保存成功')
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await noticeDelete(id)
  ElMessage.success('删除成功')
  load()
}

// ---- 已读回执报表 ----
const reportVisible = ref(false)
const reportLoading = ref(false)
const report = ref<NoticeReport>({
  notice: { id: 0, title: '', scope: 0 },
  total: 0,
  read: 0,
  unread: 0,
  list: [],
})
const reportView = ref<'read' | 'unread'>('read')
const reportPage = ref(1)
const reportLimit = ref(15)

async function openReport(row: NoticeRow): Promise<void> {
  reportVisible.value = true
  reportView.value = 'read'
  reportPage.value = 1
  report.value = {
    notice: { id: row.id, title: row.title, scope: row.scope ?? 0 },
    total: 0,
    read: 0,
    unread: 0,
    list: [],
  }
  await loadReport(row.id)
}

async function loadReport(id: number): Promise<void> {
  reportLoading.value = true
  try {
    report.value = await noticeReport({ id, view: reportView.value, page: reportPage.value, limit: reportLimit.value })
  } finally {
    reportLoading.value = false
  }
}

function onReportViewChange(): void {
  reportPage.value = 1
  loadReport(report.value.notice.id)
}

function onReportPageChange(p: number): void {
  reportPage.value = p
  loadReport(report.value.notice.id)
}

loadOptions()
</script>

<style scoped>
.form-tip {
  width: 100%;
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--art-muted);
}

.report-summary {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.report-switch {
  margin-left: auto;
}

.report-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
