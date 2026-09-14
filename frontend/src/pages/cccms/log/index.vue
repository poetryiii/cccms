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
        <el-form-item label="操作人">
          <el-input v-model="query.username" placeholder="请输入" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="操作名">
          <el-input v-model="query.title" placeholder="如 新增字典类型" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="请求路径">
          <el-input v-model="query.path" placeholder="请输入" clearable style="width: 180px" />
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
          删除选中{{ selection.length ? `（${selection.length}）` : '' }}
        </el-button>
      </template>

      <!-- 语义化操作：注解标题 + 权限节点 -->
      <template #action_name="{ row }">
        <div class="log-title">{{ row.title || '—' }}</div>
        <div v-if="row.node" class="log-node">{{ row.node }}</div>
      </template>

      <template #method="{ row }">
        <el-tag effect="plain" size="small">{{ row.method }}</el-tag>
      </template>

      <template #status_code="{ row }">
        <el-tag :type="statusTag(row.status_code)" effect="light" size="small">
          {{ row.status_code }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button link type="primary" @click="openDetail(row)">详情</el-button>
        <el-button v-auth="'cccms:log:delete'" link type="danger" @click="onDeleteOne(row.id)">
          删除
        </el-button>
      </template>
    </ArtTable>

    <el-drawer v-model="detailVisible" title="操作日志详情" size="680px">
      <template v-if="current">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="操作名">{{ current.title || '—' }}</el-descriptions-item>
          <el-descriptions-item label="权限节点">{{ current.node || '—' }}</el-descriptions-item>
          <el-descriptions-item label="操作人">{{ current.username || '—' }}</el-descriptions-item>
          <el-descriptions-item label="时间">{{ current.create_time || '—' }}</el-descriptions-item>
          <el-descriptions-item label="请求">{{ current.method }} {{ current.path }}</el-descriptions-item>
          <el-descriptions-item label="状态码">
            <el-tag :type="statusTag(current.status_code)" effect="light" size="small">
              {{ current.status_code }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="IP">{{ current.ip || '—' }}</el-descriptions-item>
          <el-descriptions-item label="耗时">{{ current.cost ?? 0 }} ms</el-descriptions-item>
          <el-descriptions-item label="User-Agent" :span="2">
            {{ current.ua || '—' }}
          </el-descriptions-item>
        </el-descriptions>

        <div class="log-block">
          <div class="log-block-title">请求参数</div>
          <pre class="log-pre">{{ pretty(current.params) }}</pre>
        </div>

        <div class="log-block">
          <div class="log-block-title">返回结果</div>
          <pre class="log-pre">{{ pretty(current.result) }}</pre>
        </div>
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:log' })

import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { logDelete, logList } from '@/api/log'
import type { ArtTableColumn } from '@/types/table'

interface Row {
  id: number
  username?: string
  method?: string
  path?: string
  /** 权限节点 slug（语义化标识） */
  node?: string
  /** 语义化操作名，取自接口的权限注解 */
  title?: string
  params?: string
  result?: string
  ip?: string
  ua?: string
  status_code?: number
  cost?: number
  create_time?: string
  [key: string]: unknown
}

interface Query {
  username: string
  title: string
  path: string
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'title', label: '操作', minWidth: 190, slot: 'action_name' },
  { prop: 'username', label: '操作人', width: 110 },
  { prop: 'method', label: '方法', width: 90, align: 'center', slot: 'method' },
  { prop: 'path', label: '请求路径', minWidth: 200 },
  { prop: 'ip', label: 'IP', width: 140, defaultHidden: true },
  { prop: 'status_code', label: '状态码', width: 100, align: 'center', slot: 'status_code' },
  { prop: 'cost', label: '耗时(ms)', width: 100, align: 'right' },
  { prop: 'create_time', label: '时间', width: 170 },
  { prop: 'action', label: '操作', width: 130, fixed: 'right', slot: 'action', lockVisible: true },
]

const {
  list, loading, total, page, limit, query, selection,
  load, search, reset, onPageChange, onLimitChange, onSelectionChange,
} = useTable<Row, Query>({
  api: logList,
  initialQuery: { username: '', title: '', path: '' },
  pageSize: 15,
})

/* ---- 详情 ---- */
const detailVisible = ref(false)
const current = ref<Row | null>(null)

function openDetail(row: Row): void {
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
    await ElMessageBox.confirm(`确定删除选中的 ${selection.value.length} 条日志？`, '批量删除', {
      type: 'warning',
    })
  } catch {
    return
  }
  await logDelete(selection.value.map((row) => row.id))
  ElMessage.success('删除成功')
  load()
}

async function onDeleteOne(id: number): Promise<void> {
  try {
    await ElMessageBox.confirm('确定删除这条日志？', '删除', { type: 'warning' })
  } catch {
    return
  }
  await logDelete([id])
  ElMessage.success('删除成功')
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
