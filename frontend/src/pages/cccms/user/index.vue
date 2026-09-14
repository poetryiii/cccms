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
        <el-form-item label="用户名">
          <el-input v-model="query.username" placeholder="请输入" clearable style="width: 170px" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="请输入" clearable style="width: 170px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 130px">
            <el-option label="启用" :value="1" />
            <el-option label="禁用" :value="0" />
          </el-select>
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button v-auth="'cccms:user:save'" type="primary" :icon="Plus" @click="openCreate">
          新增
        </el-button>
      </template>

      <!-- 点一下即把表格切到「已删除」，不是另开页面 -->
      <template #toolbar-right>
        <RecycleToggle :active="recycle" label="用户" @toggle="toggle" />
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? '启用' : '禁用' }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:user:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
        <el-button v-auth="'cccms:user:reset_password'" link type="primary" @click="openReset(row)">
          重置密码
        </el-button>
        <el-popconfirm title="确定删除该用户？" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:user:delete'" link type="danger">删除</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <!-- 新增 / 编辑 -->
    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑用户' : '新增用户'"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="请输入用户名" />
        </el-form-item>
        <el-form-item v-if="!form.id" label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password placeholder="请输入密码" />
        </el-form-item>
        <el-form-item label="昵称" prop="nickname">
          <el-input v-model="form.nickname" placeholder="请输入昵称" />
        </el-form-item>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" placeholder="请输入手机号" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" placeholder="请输入邮箱" />
        </el-form-item>
        <el-form-item label="角色" prop="role_ids">
          <el-select v-model="form.role_ids" multiple placeholder="请选择角色" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="部门" prop="dept_ids">
          <el-tree-select
            v-model="form.dept_ids"
            :data="deptTreeData"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            multiple
            show-checkbox
            check-strictly
            placeholder="请选择部门"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="岗位" prop="post_ids">
          <el-select v-model="form.post_ids" multiple placeholder="请选择岗位" style="width: 100%">
            <el-option v-for="p in postOptions" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">确定</el-button>
      </template>
    </el-dialog>

    <!-- 重置密码 -->
    <el-dialog v-model="resetVisible" title="重置密码" width="420px">
      <el-form :model="resetForm" label-width="80px">
        <el-form-item label="新密码">
          <el-input
            v-model="resetForm.password"
            type="password"
            show-password
            placeholder="至少 6 位"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="resetVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitReset">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:user' })

import { onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import { userDelete, userList, userRead, userResetPassword, userSave, userUpdate } from '@/api/user'
import { roleList } from '@/api/role'
import { deptTree } from '@/api/dept'
import { postList } from '@/api/post'
import type { ArtTableColumn } from '@/types/table'

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

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'username', label: '用户名', minWidth: 120 },
  { prop: 'nickname', label: '昵称', minWidth: 120 },
  { prop: 'phone', label: '手机号', minWidth: 130, defaultHidden: true },
  { prop: 'email', label: '邮箱', minWidth: 180, defaultHidden: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', slot: 'status' },
  { prop: 'login_time', label: '最后登录', width: 170 },
  { prop: 'action', label: '操作', width: 210, fixed: 'right', slot: 'action', lockVisible: true },
]

// 回收站开关：必须声明在 useTable 之前 —— 列表闭包在 setup 阶段就会执行一次
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('user', {
  reload: () => search(),
})

const {
  list, loading, total, page, limit, query,
  load, search, reset, onPageChange, onLimitChange,
} = useTable<Row, Query>({
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

const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' },
  ],
}

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
    ElMessage.success('保存成功')
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await userDelete(id)
  ElMessage.success('删除成功')
  load()
}

/* ---- 重置密码 ---- */
const resetVisible = ref(false)
const resetForm = reactive({ id: 0, password: '' })

function openReset(record: Row): void {
  resetForm.id = record.id
  resetForm.password = ''
  resetVisible.value = true
}

async function submitReset(): Promise<void> {
  if (!resetForm.password || resetForm.password.length < 6) {
    ElMessage.warning('密码至少 6 位')
    return
  }
  saving.value = true
  try {
    await userResetPassword({ ...resetForm })
    ElMessage.success('重置成功')
    resetVisible.value = false
  } finally {
    saving.value = false
  }
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
