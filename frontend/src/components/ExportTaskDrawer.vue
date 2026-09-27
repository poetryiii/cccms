<template>
  <el-drawer :model-value="store.visible" :title="t('export.title')" size="560px" append-to-body @close="store.close()">
    <div class="export-toolbar">
      <el-button size="small" :icon="Refresh" :loading="store.loading" @click="store.load()">
        {{ t('export.refresh') }}
      </el-button>
      <span class="export-hint">{{ t('export.hint') }}</span>
    </div>

    <el-empty v-if="store.items.length === 0 && !store.loading" :description="t('export.empty')" :image-size="80" />
    <el-table v-else v-loading="store.loading" :data="store.items" size="small" class="export-table">
      <el-table-column :label="t('export.type')" width="120">
        <template #default="{ row }">{{ typeLabel(row.type) }}</template>
      </el-table-column>
      <el-table-column :label="t('export.rows')" width="80" align="right">
        <template #default="{ row }">{{ row.total_rows }}</template>
      </el-table-column>
      <el-table-column :label="t('export.createdAt')" min-width="150">
        <template #default="{ row }">{{ row.create_time }}</template>
      </el-table-column>
      <el-table-column :label="t('export.status')" width="90" align="center">
        <template #default="{ row }">
          <el-tag :type="statusTag(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column :label="t('table.action')" width="110" align="center">
        <template #default="{ row }">
          <!-- 完成直接下载；待处理时点击会触发后端同步生成并下载（Windows 无定时进程的兜底） -->
          <el-button
            v-if="row.status === 0 || row.status === 2"
            link
            type="primary"
            :icon="Download"
            @click="download(row)"
          >
            {{ row.status === 2 ? t('export.download') : t('export.generateDownload') }}
          </el-button>
          <span v-else-if="row.status === 3" class="export-error" :title="row.error">{{ row.error }}</span>
        </template>
      </el-table-column>
    </el-table>
  </el-drawer>
</template>

<script setup lang="ts">
import { Download, Refresh } from '@element-plus/icons-vue'
import { useI18n } from 'vue-i18n'
import { exportTaskDownload, type ExportTaskRow } from '@/api/export'
import { useExportTaskStore } from '@/stores/exportTask'

const { t } = useI18n({ useScope: 'global' })
const store = useExportTaskStore()

function typeLabel(type: string): string {
  const map: Record<string, string> = {
    log: t('export.type_log'),
  }

  return map[type] ?? type
}

function statusText(status: number): string {
  const map: Record<number, string> = {
    0: t('export.statusPending'),
    1: t('export.statusRunning'),
    2: t('export.statusDone'),
    3: t('export.statusFailed'),
    4: t('export.statusExpired'),
  }

  return map[status] ?? String(status)
}

function statusTag(status: number): 'info' | 'warning' | 'success' | 'danger' {
  const map: Record<number, 'info' | 'warning' | 'success' | 'danger'> = {
    0: 'info',
    1: 'warning',
    2: 'success',
    3: 'danger',
    4: 'info',
  }

  return map[status] ?? 'info'
}

function download(row: ExportTaskRow): void {
  void exportTaskDownload(row.id)
}
</script>

<style scoped>
.export-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.export-hint {
  font-size: 12px;
  color: var(--art-muted);
}

.export-error {
  display: inline-block;
  max-width: 90px;
  overflow: hidden;
  font-size: 12px;
  color: var(--art-danger);
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
