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
        <el-form-item label="岗位名称">
          <el-input v-model="query.name" placeholder="请输入" clearable style="width: 180px" />
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button v-auth="'cccms:post:save'" type="primary" :icon="Plus" @click="openCreate">
          新增
        </el-button>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" label="岗位" @toggle="toggle" />
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? '启用' : '禁用' }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:post:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
        <el-popconfirm title="确定删除该岗位？" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:post:delete'" link type="danger">删除</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑岗位' : '新增岗位'"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="88px">
        <el-form-item label="岗位编码" prop="code">
          <el-input v-model="form.code" placeholder="如 sale_manager" />
        </el-form-item>
        <el-form-item label="岗位名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入岗位名称" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
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
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:post' })

import { reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import { postDelete, postList, postSave, postUpdate } from '@/api/post'
import type { ArtTableColumn } from '@/types/table'

interface Row {
  id: number
  code?: string
  name?: string
  sort?: number
  status?: number
  [key: string]: unknown
}

interface Query {
  name: string
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'code', label: '岗位编码', minWidth: 150 },
  { prop: 'name', label: '岗位名称', minWidth: 150 },
  { prop: 'sort', label: '排序', width: 90, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', slot: 'status' },
  { prop: 'action', label: '操作', width: 130, fixed: 'right', slot: 'action', lockVisible: true },
]

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('post', {
  reload: () => search(),
})

const {
  list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange,
} = useTable<Row, Query>({
  api: (params) => postList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '' },
})

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = { id: 0, code: '', name: '', sort: 0, status: 1 }
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules: FormRules = {
  code: [{ required: true, message: '请输入岗位编码', trigger: 'blur' }],
  name: [{ required: true, message: '请输入岗位名称', trigger: 'blur' }],
}

function openCreate(): void {
  Object.assign(form, emptyForm)
  formVisible.value = true
}

function openEdit(record: Row): void {
  Object.assign(form, emptyForm, record)
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
      await postUpdate({ ...form })
    } else {
      await postSave({ ...form })
    }
    ElMessage.success('保存成功')
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await postDelete(id)
  ElMessage.success('删除成功')
  load()
}
</script>
