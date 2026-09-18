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

      <template #action="{ row }">
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
      width="680px"
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
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:notice' })

import { reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTable } from '@/composables/useTable'
import { noticeDelete, noticeList, noticeSave, noticeUpdate, type NoticeRow } from '@/api/notice'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  title: string
  type: number | ''
  status: number | ''
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'title', label: '标题', minWidth: 220 },
  { prop: 'type', label: '类型', width: 90, align: 'center', slot: 'type' },
  { prop: 'level', label: '级别', width: 90, align: 'center', slot: 'level' },
  { prop: 'status', label: '状态', width: 100, align: 'center', slot: 'status' },
  { prop: 'publish_at', label: '发布时间', width: 170 },
  { prop: 'expire_at', label: '过期时间', width: 170, defaultHidden: true },
  { prop: 'read_count', label: '已读人数', width: 100, align: 'right' },
  { prop: 'create_time', label: '创建时间', width: 170, defaultHidden: true },
  { prop: 'action', label: '操作', width: 130, fixed: 'right', slot: 'action', lockVisible: true },
]

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('notice', {
  reload: () => search(),
})

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  NoticeRow,
  Query
>({
  api: (params) => noticeList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { title: '', type: '', status: '' },
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
  publish_at: null as string | null,
  expire_at: null as string | null,
  content: '',
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules: FormRules = {
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
}

function openCreate(): void {
  Object.assign(form, emptyForm)
  formVisible.value = true
}

function openEdit(row: NoticeRow): void {
  Object.assign(form, emptyForm, {
    id: row.id,
    title: row.title,
    type: row.type,
    level: row.level,
    status: row.status,
    publish_at: row.publish_at,
    expire_at: row.expire_at,
    content: row.content,
  })
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    const payload = { ...form }
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
</script>
