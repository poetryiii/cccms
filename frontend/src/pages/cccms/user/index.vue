<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      :recycle="recycle"
      selection
      row-key="id"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @search="search"
      @reset="reset"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @restore="onRestore"
      @force-delete="onForceDelete"
      @selection-change="onSelectionChange"
    >
      <template #search>
        <el-form-item :label="t('user.username')">
          <el-input v-model="query.username" :placeholder="t('user.pleaseInput')" clearable style="width: 170px" />
        </el-form-item>
        <el-form-item :label="t('user.nickname')">
          <el-input v-model="query.nickname" :placeholder="t('user.pleaseInput')" clearable style="width: 170px" />
        </el-form-item>
        <el-form-item :label="t('user.status')">
          <el-select v-model="query.status" :placeholder="t('user.all')" clearable style="width: 130px">
            <el-option :label="t('user.enabled')" :value="1" />
            <el-option :label="t('user.disabled')" :value="0" />
          </el-select>
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button v-auth="'cccms:user:save'" type="primary" :icon="Plus" @click="openCreate">
          {{ t('common.create') }}
        </el-button>

        <!-- 批量操作：只在勾选后可用；越权 / 受保护的行由后端跳过并回报 -->
        <el-dropdown :disabled="selected.length === 0" @command="onBatchCommand">
          <el-button :disabled="selected.length === 0">
            {{ selected.length ? t('user.batchActionCount', { count: selected.length }) : t('user.batchAction') }}
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_status')" command="enable">
                {{ t('user.batchEnable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_status')" command="disable">
                {{ t('user.batchDisable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_assign')" command="assign" divided>
                {{ t('user.batchAssign') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:user:batch_delete')" command="delete" divided>
                {{ t('user.batchDelete') }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>

      <!-- 点一下即把表格切到「已删除」，不是另开页面 -->
      <template #toolbar-right>
        <el-button v-auth="'cccms:user:export'" :icon="Download" @click="onExport">
          {{ t('common.export') }}
        </el-button>
        <el-button v-auth="'cccms:user:import'" :icon="Upload" @click="openImport">
          {{ t('common.import') }}
        </el-button>
        <RecycleToggle :active="recycle" :label="t('user.entityLabel')" @toggle="toggle" />
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('user.enabled') : t('user.disabled') }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:user:update'" link type="primary" @click="openEdit(row)">
          {{ t('common.edit') }}
        </el-button>
        <el-button v-auth="'cccms:user:reset_password'" link type="primary" @click="openReset(row)">
          {{ t('user.resetPassword') }}
        </el-button>
        <el-popconfirm :title="t('user.confirmDelete')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:user:delete'" link type="danger">{{ t('common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 新增 / 编辑 -->
    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('user.editUser') : t('user.createUser')"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item :label="t('user.username')" prop="username">
          <el-input v-model="form.username" :placeholder="t('user.usernameRequired')" />
        </el-form-item>
        <el-form-item v-if="!form.id" :label="t('user.password')" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            :placeholder="t('user.passwordPlaceholder')"
          />
        </el-form-item>
        <el-form-item :label="t('user.nickname')" prop="nickname">
          <el-input v-model="form.nickname" :placeholder="t('user.nicknamePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('user.phone')" prop="phone">
          <el-input v-model="form.phone" :placeholder="t('user.phonePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('user.email')" prop="email">
          <el-input v-model="form.email" :placeholder="t('user.emailPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('user.role')" prop="role_ids">
          <el-select v-model="form.role_ids" multiple :placeholder="t('user.rolePlaceholder')" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('user.dept')" prop="dept_ids">
          <el-tree-select
            v-model="form.dept_ids"
            :data="deptTreeData"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            multiple
            show-checkbox
            check-strictly
            :placeholder="t('user.deptPlaceholder')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('user.post')" prop="post_ids">
          <el-select v-model="form.post_ids" multiple :placeholder="t('user.postPlaceholder')" style="width: 100%">
            <el-option v-for="p in postOptions" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('user.status')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('user.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('user.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 重置密码 -->
    <el-dialog v-model="resetVisible" :title="t('user.resetPassword')" width="420px">
      <el-form ref="resetRef" :model="resetForm" :rules="resetRules" label-width="80px">
        <el-form-item :label="t('user.newPassword')" prop="password">
          <el-input
            v-model="resetForm.password"
            type="password"
            show-password
            :placeholder="t('user.passwordPlaceholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="resetVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitReset">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 导入用户（CSV） -->
    <el-dialog v-model="importVisible" :title="t('user.importUser')" width="580px">
      <el-alert type="info" :closable="false" show-icon :title="t('user.importAlert')" style="margin-bottom: 12px" />

      <div class="import-actions">
        <el-button link type="primary" @click="onTemplate">{{ t('user.downloadTemplate') }}</el-button>
      </div>

      <el-upload
        ref="uploadRef"
        drag
        :action="USER_IMPORT_URL"
        :headers="uploadHeaders"
        name="file"
        accept=".csv"
        :limit="1"
        :auto-upload="false"
        :on-success="onImportSuccess"
        :on-error="onImportError"
      >
        <el-icon class="el-icon--upload"><UploadFilled /></el-icon>
        <div class="el-upload__text">
          {{ t('user.uploadDrag') }}<em>{{ t('user.uploadClick') }}</em>
        </div>
      </el-upload>

      <div v-if="importResult" class="import-result">
        <p>
          {{
            t('user.importSummary', {
              total: importResult.total,
              created: importResult.created,
              updated: importResult.updated,
              failed: importResult.failed.length,
            })
          }}
        </p>
        <ul v-if="importResult.failed.length" class="import-failed">
          <li v-for="(msg, index) in importResult.failed.slice(0, 20)" :key="index">{{ msg }}</li>
        </ul>
      </div>

      <template #footer>
        <el-button @click="importVisible = false">{{ t('user.close') }}</el-button>
        <el-button type="primary" :loading="importing" @click="submitImport">{{ t('user.startImport') }}</el-button>
      </template>
    </el-dialog>

    <!-- 批量分配角色 / 部门 / 岗位 -->
    <el-dialog v-model="assignVisible" :title="t('user.batchAssign')" width="560px">
      <el-alert
        type="info"
        :closable="false"
        show-icon
        :title="t('user.assignTip', { count: selected.length })"
        style="margin-bottom: 12px"
      />
      <el-form label-width="60px">
        <el-form-item :label="t('user.role')">
          <el-select v-model="assignForm.role_ids" multiple :placeholder="t('user.keepAsIs')" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('user.dept')">
          <el-tree-select
            v-model="assignForm.dept_ids"
            :data="deptTreeData"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            multiple
            show-checkbox
            check-strictly
            :placeholder="t('user.keepAsIs')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('user.post')">
          <el-select v-model="assignForm.post_ids" multiple :placeholder="t('user.keepAsIs')" style="width: 100%">
            <el-option v-for="p in postOptions" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="assignVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitAssign">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:user' })

import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { ArrowDown, Download, Plus, Upload, UploadFilled } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import { useUserStore } from '@/stores/user'
import {
  USER_IMPORT_URL,
  userBatchAssign,
  userBatchDelete,
  userBatchStatus,
  userDelete,
  userExport,
  userList,
  userRead,
  userResetPassword,
  userSave,
  userTemplate,
  userUpdate,
  type BatchResult,
  type UserImportResult,
} from '@/api/user'
import { roleList } from '@/api/role'
import { deptTree } from '@/api/dept'
import { postList } from '@/api/post'
import { getToken } from '@/utils/auth'
import { passwordValidator } from '@/utils/password'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })
/**
 * 批量操作菜单项用 `v-if="hasAuth(...)"` 而不是 `v-auth`：
 * ElDropdownItem 的根节点不是单个元素（内部是 ElRovingFocusItem），
 * 运行时指令挂不上去，Vue 会告警且权限隐藏失效。
 */
const { hasAuth } = useUserStore()

interface Row {
  id: number
  username?: string
  nickname?: string
  phone?: string
  email?: string
  status?: number
  [key: string]: unknown
}

interface Query {
  username: string
  nickname: string
  status?: number
}

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'username', label: t('user.username'), minWidth: 120 },
  { prop: 'nickname', label: t('user.nickname'), minWidth: 120 },
  { prop: 'phone', label: t('user.phone'), minWidth: 130, defaultHidden: true },
  { prop: 'email', label: t('user.email'), minWidth: 180, defaultHidden: true },
  { prop: 'status', label: t('user.status'), width: 90, align: 'center', slot: 'status' },
  { prop: 'login_time', label: t('user.lastLogin'), width: 170 },
  { prop: 'action', label: t('table.action'), width: 210, fixed: 'right', slot: 'action', lockVisible: true },
])

// 回收站开关：必须声明在 useTable 之前 —— 列表闭包在 setup 阶段就会执行一次
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('user', {
  reload: () => search(),
})

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  Row,
  Query
>({
  api: (params) => userList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { username: '', nickname: '', status: undefined },
})

/* ---- 表单 ---- */
const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  username: '',
  password: '',
  nickname: '',
  phone: '',
  email: '',
  status: 1,
  role_ids: [] as number[],
  dept_ids: [] as number[],
  post_ids: [] as number[],
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules = computed<FormRules>(() => ({
  username: [{ required: true, message: t('user.usernameRequired'), trigger: 'blur' }],
  password: [
    { required: true, message: t('user.passwordRequired'), trigger: 'blur' },
    // 身份信息用表单里已填的用户名 / 昵称，提前拦住「密码就是用户名」这类可猜口令
    { validator: passwordValidator(() => ({ username: form.username, nickname: form.nickname })), trigger: 'blur' },
  ],
}))

function openCreate(): void {
  Object.assign(form, emptyForm, { role_ids: [], dept_ids: [], post_ids: [] })
  formVisible.value = true
}

async function openEdit(record: Row): Promise<void> {
  Object.assign(form, emptyForm, { role_ids: [], dept_ids: [], post_ids: [] })
  const detail = await userRead(record.id)
  Object.assign(form, detail)
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (form.id) {
      await userUpdate({ ...form })
    } else {
      await userSave({ ...form })
    }
    ElMessage.success(t('user.saveSuccess'))
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await userDelete(id)
  ElMessage.success(t('user.deleteSuccess'))
  load()
}

/* ---- 重置密码 ---- */
const resetVisible = ref(false)
const resetRef = ref<FormInstance>()
const resetForm = reactive({ id: 0, password: '' })
const resetUsername = ref('')
const resetRules = computed<FormRules>(() => ({
  password: [
    { required: true, message: t('user.newPasswordRequired'), trigger: 'blur' },
    // 重置的是既有账号，用列表里那一行的用户名做身份比对
    { validator: passwordValidator(() => ({ username: resetUsername.value })), trigger: 'blur' },
  ],
}))

function openReset(record: Row): void {
  resetForm.id = record.id
  resetForm.password = ''
  resetUsername.value = record.username || ''
  resetVisible.value = true
}

async function submitReset(): Promise<void> {
  const valid = await resetRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    await userResetPassword({ ...resetForm })
    ElMessage.success(t('user.resetSuccess'))
    resetVisible.value = false
  } finally {
    saving.value = false
  }
}

/* ---- 批量操作 ---- */
const selected = ref<Row[]>([])

function onSelectionChange(rows: Row[]): void {
  selected.value = rows
}

/** 统一的批量结果提示：跳过的行单独说明，避免「点了没反应」的困惑 */
function reportBatch(result: BatchResult, verb: string): void {
  if (result.skipped.length > 0) {
    ElMessage.warning(
      t('user.batchSkipped', { action: verb, affected: result.affected, skipped: result.skipped.length }),
    )
  } else {
    ElMessage.success(t('user.batchDone', { action: verb, affected: result.affected }))
  }
}

async function onBatchCommand(command: string): Promise<void> {
  const ids = selected.value.map((row) => row.id)
  if (ids.length === 0) {
    return
  }

  if (command === 'assign') {
    Object.assign(assignForm, { role_ids: [], dept_ids: [], post_ids: [] })
    assignVisible.value = true
    return
  }

  const isDelete = command === 'delete'
  const confirmMessage = isDelete
    ? t('user.confirmBatchDelete', { count: ids.length })
    : command === 'enable'
      ? t('user.confirmBatchEnable', { count: ids.length })
      : t('user.confirmBatchDisable', { count: ids.length })
  try {
    await ElMessageBox.confirm(confirmMessage, isDelete ? t('user.batchDelete') : t('user.batchToggle'), {
      type: 'warning',
    })
  } catch {
    return
  }

  const result = isDelete ? await userBatchDelete(ids) : await userBatchStatus(ids, command === 'enable' ? 1 : 0)
  reportBatch(result, isDelete ? t('user.batchVerbDelete') : t('user.batchVerbUpdate'))
  selected.value = []
  load()
}

/* ---- 批量分配 ---- */
const assignVisible = ref(false)
const assignForm = reactive<{ role_ids: number[]; dept_ids: number[]; post_ids: number[] }>({
  role_ids: [],
  dept_ids: [],
  post_ids: [],
})

async function submitAssign(): Promise<void> {
  const ids = selected.value.map((row) => row.id)
  // 只提交有内容的字段：未选的字段后端会保持原样，传空数组则会被清空
  const payload: { ids: number[]; role_ids?: number[]; dept_ids?: number[]; post_ids?: number[] } = { ids }
  if (assignForm.role_ids.length) {
    payload.role_ids = assignForm.role_ids
  }
  if (assignForm.dept_ids.length) {
    payload.dept_ids = assignForm.dept_ids
  }
  if (assignForm.post_ids.length) {
    payload.post_ids = assignForm.post_ids
  }
  if (payload.role_ids === undefined && payload.dept_ids === undefined && payload.post_ids === undefined) {
    ElMessage.warning(t('user.assignAtLeastOne'))
    return
  }

  saving.value = true
  try {
    const result = await userBatchAssign(payload)
    reportBatch(result, t('user.batchVerbAssign'))
    assignVisible.value = false
    selected.value = []
    load()
  } finally {
    saving.value = false
  }
}

/* ---- 导出 / 导入 ---- */

/** 导出当前筛选与数据范围内的用户 */
function onExport(): void {
  void userExport({ ...query, trashed: recycle.value ? 1 : 0 })
}

function onTemplate(): void {
  void userTemplate()
}

const importVisible = ref(false)
const importing = ref(false)
const importResult = ref<UserImportResult | null>(null)
const uploadRef = ref<{ submit: () => void; clearFiles: () => void } | null>(null)

/** el-upload 直传，需自己带鉴权头（与附件上传同样的做法） */
const uploadHeaders = computed(() => ({ Authorization: `Bearer ${getToken() ?? ''}` }))

function openImport(): void {
  importResult.value = null
  importVisible.value = true
  uploadRef.value?.clearFiles()
}

function submitImport(): void {
  importing.value = true
  uploadRef.value?.submit()
}

function onImportSuccess(response: { code?: number; message?: string; data?: UserImportResult }): void {
  importing.value = false
  if (!response || response.code !== 0) {
    ElMessage.error(response?.message || t('user.importError'))
    return
  }

  importResult.value = response.data ?? null
  const failed = response.data?.failed.length ?? 0
  if (failed === 0) {
    ElMessage.success(
      t('user.importDone', { created: response.data?.created ?? 0, updated: response.data?.updated ?? 0 }),
    )
  } else {
    ElMessage.warning(t('user.importPartial', { failed }))
  }
  load()
}

function onImportError(): void {
  importing.value = false
  ElMessage.error(t('user.importFailed'))
}

/* ---- 下拉数据 ---- */
const roleOptions = ref<Row[]>([])
const postOptions = ref<Row[]>([])
const deptTreeData = ref<Record<string, unknown>[]>([])

onMounted(async () => {
  const [roles, posts, depts] = await Promise.all([
    roleList({ page: 1, limit: 999 }),
    postList({ page: 1, limit: 999 }),
    deptTree(),
  ])
  roleOptions.value = roles.list as Row[]
  postOptions.value = posts.list as Row[]
  deptTreeData.value = depts as Record<string, unknown>[]
})
</script>

<style scoped>
.import-actions {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 8px;
}

.import-result {
  margin-top: 12px;
  font-size: 13px;
  color: var(--art-main);
}

.import-failed {
  max-height: 180px;
  padding-left: 18px;
  margin: 6px 0 0;
  overflow-y: auto;
  font-size: 12px;
  color: var(--art-danger);
}
</style>
