<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :total="total"
      row-key="jti"
      v-model:page="page"
      v-model:limit="limit"
      @refresh="load"
      @search="search"
      @reset="reset"
      @page-change="onPageChange"
      @size-change="onLimitChange"
    >
      <template #search>
        <el-form-item label="关键词">
          <el-input v-model="query.keyword" placeholder="账号 / 昵称 / IP" clearable style="width: 220px" />
        </el-form-item>
      </template>

      <template #toolbar>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="会话来自 Redis 索引；「强制下线」会同时作废已发出的令牌，对方下一次请求即失效。"
        />
      </template>

      <template #username="{ row }">
        <div class="online-user">{{ row.username || '—' }}</div>
        <div class="online-nick">{{ row.nickname || '—' }}</div>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:online:kick'" link type="danger" @click="onKick(row)"> 强制下线 </el-button>
        <el-button v-auth="'cccms:online:kick_user'" link type="warning" @click="onKickUser(row)">
          该用户全部下线
        </el-button>
      </template>
    </ArtTable>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:online' })

import { ElMessage, ElMessageBox } from 'element-plus'
import ArtTable from '@/components/core/ArtTable.vue'
import { useTable } from '@/composables/useTable'
import { onlineKick, onlineKickUser, onlineList, type OnlineSession } from '@/api/online'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  keyword: string
}

const columns: ArtTableColumn[] = [
  { prop: 'username', label: '用户', slot: 'username' },
  { prop: 'ip', label: 'IP', width: 150 },
  { prop: 'login_at', label: '登录时间', width: 170 },
  { prop: 'last_at', label: '最后活跃', width: 170 },
  { prop: 'ua', label: 'User-Agent', minWidth: 240, defaultHidden: true },
  { prop: 'action', label: '操作', width: 210, fixed: 'right', slot: 'action', lockVisible: true },
]

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  OnlineSession,
  Query
>({
  api: onlineList,
  initialQuery: { keyword: '' },
})

async function onKick(row: OnlineSession): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定让「${row.username}」的该会话立即下线？`, '强制下线', { type: 'warning' })
  } catch {
    return
  }
  await onlineKick(row.jti)
  ElMessage.success('已强制下线')
  load()
}

async function onKickUser(row: OnlineSession): Promise<void> {
  try {
    await ElMessageBox.confirm(`将让「${row.username}」的所有登录会话全部失效，确定继续？`, '强制用户下线', {
      type: 'warning',
    })
  } catch {
    return
  }
  const res = await onlineKickUser(row.user_id)
  ElMessage.success(`已强制下线 ${res.sessions} 个会话`)
  load()
}
</script>

<style scoped>
.online-user {
  color: var(--art-main);
}

.online-nick {
  margin-top: 1px;
  font-size: 12px;
  color: var(--art-muted);
}
</style>
