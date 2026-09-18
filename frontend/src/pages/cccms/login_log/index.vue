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
      @search="onSearch"
      @reset="onReset"
      @page-change="onPageChange"
      @size-change="onLimitChange"
      @selection-change="onSelectionChange"
    >
      <template #search>
        <el-form-item label="账号">
          <el-input v-model="query.username" placeholder="请输入" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="IP">
          <el-input v-model="query.ip" placeholder="请输入" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="结果">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="成功" :value="1" />
            <el-option label="失败" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="登录时间">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button
          v-auth="'cccms:login_log:delete'"
          type="danger"
          plain
          :icon="Delete"
          :disabled="selection.length === 0"
          @click="onBatchDelete"
        >
          删除选中{{ selection.length ? `（${selection.length}）` : '' }}
        </el-button>
        <el-button v-auth="'cccms:login_log:clear'" type="danger" plain :icon="Delete" @click="onClear">
          清空
        </el-button>
      </template>

      <template #toolbar-right>
        <el-button v-auth="'cccms:login_log:export'" :icon="Download" @click="onExport"> 导出 </el-button>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'danger'" effect="light" round>
          {{ row.status === 1 ? '成功' : '失败' }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:login_log:delete'" link type="danger" @click="onDeleteOne(row.id)"> 删除 </el-button>
      </template>
    </ArtTable>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:login_log' })

import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Download } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { loginLogClear, loginLogDelete, loginLogExport, loginLogList, type LoginLogRow } from '@/api/loginLog'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  username: string
  ip: string
  status: number | ''
  start: string
  end: string
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'username', label: '账号', width: 140 },
  { prop: 'status', label: '结果', width: 90, align: 'center', slot: 'status' },
  { prop: 'message', label: '说明', minWidth: 200 },
  { prop: 'ip', label: 'IP', width: 140 },
  { prop: 'ua', label: 'User-Agent', minWidth: 220, defaultHidden: true },
  { prop: 'create_time', label: '登录时间', width: 170 },
  { prop: 'action', label: '操作', width: 90, fixed: 'right', slot: 'action', lockVisible: true },
]

const dateRange = ref<[string, string] | null>(null)

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
} = useTable<LoginLogRow, Query>({
  api: loginLogList,
  initialQuery: { username: '', ip: '', status: '', start: '', end: '' },
})

/** 日期范围是本地控件状态，提交前同步进查询条件 */
function onSearch(): void {
  query.start = dateRange.value?.[0] ?? ''
  query.end = dateRange.value?.[1] ?? ''
  search()
}

function onReset(): void {
  dateRange.value = null
  reset()
}

function onExport(): void {
  void loginLogExport({ ...query })
}

async function onBatchDelete(): Promise<void> {
  if (selection.value.length === 0) {
    return
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${selection.value.length} 条登录日志？`, '批量删除', { type: 'warning' })
  } catch {
    return
  }
  await loginLogDelete(selection.value.map((row) => row.id))
  ElMessage.success('删除成功')
  load()
}

async function onDeleteOne(id: number): Promise<void> {
  try {
    await ElMessageBox.confirm('确定删除这条登录日志？', '删除', { type: 'warning' })
  } catch {
    return
  }
  await loginLogDelete([id])
  ElMessage.success('删除成功')
  load()
}

async function onClear(): Promise<void> {
  try {
    await ElMessageBox.confirm('将清空全部登录日志，且不可恢复。确定继续？', '清空登录日志', { type: 'warning' })
  } catch {
    return
  }
  const res = await loginLogClear()
  ElMessage.success(`已清空 ${res.deleted} 条`)
  load()
}
</script>
