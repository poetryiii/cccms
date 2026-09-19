<template>
  <div class="art-scroll">
    <div class="art-page">
      <!-- 环境不可用 -->
      <el-alert
        v-if="overview && !overview.enabled"
        type="warning"
        :closable="false"
        show-icon
        :title="t('upgrade.disabledTitle')"
        :description="t('upgrade.disabledDesc')"
      />
      <el-alert
        v-else-if="overview && !overview.git_available"
        type="error"
        :closable="false"
        show-icon
        :title="t('upgrade.noGitTitle')"
        :description="t('upgrade.noGitDesc')"
      />

      <template v-else-if="overview">
        <!-- 版本概览 -->
        <el-card shadow="never" class="block version-card">
          <div class="version">
            <div class="version-side">
              <div class="version-badge current">{{ t('upgrade.current') }}</div>
              <div class="version-body">
                <div class="version-name">{{ overview.current.base || t('upgrade.noBaseline') }}</div>
                <div class="version-meta">
                  <span v-if="overview.current.commit" class="mono">
                    {{ short(overview.current.commit) }}
                  </span>
                  <span v-if="overview.current.synced_at">{{ overview.current.synced_at }}</span>
                  <span v-if="overview.current.files">
                    {{ t('upgrade.upstreamFiles', { count: overview.current.files }) }}
                  </span>
                </div>
              </div>
            </div>

            <div class="version-arrow">
              <el-icon :size="20"><ArtIcon name="icon-refresh-right" /></el-icon>
            </div>

            <div class="version-side">
              <div class="version-badge" :class="plan?.has_update ? 'target' : 'idle'">
                {{ t('upgrade.target') }}
              </div>
              <div class="version-body">
                <div class="version-name">
                  {{ plan ? plan.ref : overview.track }}
                </div>
                <div class="version-meta">
                  <span v-if="plan?.commit" class="mono">{{ short(plan.commit) }}</span>
                  <template v-if="plan">
                    <span v-if="!plan.has_update">{{ t('upgrade.upToDate') }}</span>
                    <span v-else>{{ t('upgrade.commitCount', { count: plan.commits.length }) }}</span>
                  </template>
                  <span v-else>{{ t('upgrade.notChecked') }}</span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="plan" class="stats">
            <div class="stat">
              <div class="stat-value success">{{ plan.summary.safe || 0 }}</div>
              <div class="stat-label">{{ t('upgrade.statSafe') }}</div>
            </div>
            <div class="stat">
              <div class="stat-value primary">{{ plan.summary.new || 0 }}</div>
              <div class="stat-label">{{ t('upgrade.statNew') }}</div>
            </div>
            <div class="stat">
              <div class="stat-value" :class="plan.pending ? 'danger' : 'muted'">{{ plan.pending }}</div>
              <div class="stat-label">{{ t('upgrade.statPending') }}</div>
            </div>
            <div class="stat">
              <div class="stat-value">
                <span class="added">+{{ plan.lines.added }}</span>
                <span class="deleted">-{{ plan.lines.deleted }}</span>
              </div>
              <div class="stat-label">{{ t('upgrade.statLines') }}</div>
            </div>
          </div>
        </el-card>

        <!-- 未建立基线：引导 -->
        <el-card v-if="!overview.initialized" shadow="never" class="block">
          <el-empty :description="t('upgrade.emptyBaseline')">
            <el-button v-auth="'cccms:upgrade:init'" type="primary" :loading="initing" @click="onInit">
              {{ t('upgrade.initNow') }}
            </el-button>
          </el-empty>
          <p class="tip">
            {{ t('upgrade.initTip') }}
          </p>
        </el-card>

        <template v-else>
          <!-- 操作栏 -->
          <el-card shadow="never" class="block">
            <div class="toolbar">
              <div class="filters">
                <span class="filter-label">{{ t('upgrade.sourceLabel') }}</span>
                <el-select v-model="source" style="width: 190px" @change="onSourceChange">
                  <el-option v-for="s in overview.sources" :key="s.key" :label="s.label" :value="s.key" />
                </el-select>

                <span class="filter-label">{{ t('upgrade.targetRefLabel') }}</span>
                <el-select v-model="targetRef" style="width: 190px" @change="onCheck">
                  <el-option :label="t('upgrade.trackBranch', { name: overview.track })" :value="TRACK_VALUE" />
                  <el-option v-for="tag in tags" :key="tag" :label="tag" :value="tag" />
                </el-select>
              </div>

              <div class="actions">
                <el-button :loading="checking" :icon="Search" @click="onCheck"> {{ t('upgrade.check') }} </el-button>
                <el-button
                  v-auth="'cccms:upgrade:run'"
                  type="primary"
                  :icon="Refresh"
                  :loading="running"
                  :disabled="!plan"
                  @click="onRun"
                >
                  {{ t('upgrade.run') }}
                </el-button>
              </div>
            </div>

            <div v-if="plan && plan.pending > 0" class="force-row">
              <el-checkbox v-model="force">
                {{ t('upgrade.forceLabel', { count: plan.pending }) }}
              </el-checkbox>
              <span class="force-hint">{{ t('upgrade.forceHint') }}</span>
            </div>

            <el-alert
              v-if="runResult"
              class="result"
              :type="runResult.written || runResult.removed ? 'success' : 'info'"
              :closable="false"
              show-icon
            >
              <template #title>
                {{
                  t('upgrade.lastRun', {
                    written: runResult.written,
                    removed: runResult.removed,
                    backed: runResult.backed,
                  })
                }}
              </template>
              <template #default>
                <div v-if="runResult.backup_dir" class="mono">
                  {{ t('upgrade.backupDir', { path: runResult.backup_dir }) }}
                </div>
                <div v-if="runResult.skipped.length">
                  {{ t('upgrade.skipped', { count: runResult.skipped.length }) }}
                </div>
                <div v-if="runResult.maintenance_error" class="danger-text">
                  {{ t('upgrade.maintenanceError', { error: runResult.maintenance_error }) }}
                </div>
                <div v-if="runResult.need_reload" class="danger-text">
                  {{ t('upgrade.needReload') }}
                </div>
              </template>
            </el-alert>
          </el-card>

          <!-- 变更明细 -->
          <el-card shadow="never" class="block">
            <el-tabs v-model="tab">
              <el-tab-pane :label="t('upgrade.filesTab', { count: plan ? plan.files.length : 0 })" name="files">
                <div class="table-tools">
                  <el-radio-group v-model="kindFilter" size="small">
                    <el-radio-button value="changed">{{ t('upgrade.filterChanged') }}</el-radio-button>
                    <el-radio-button value="all">{{ t('upgrade.filterAll') }}</el-radio-button>
                    <el-radio-button value="kept">{{ t('upgrade.filterKept') }}</el-radio-button>
                  </el-radio-group>
                  <span class="tip-inline">
                    {{ t('upgrade.localOnlyTip', { count: plan?.local_only || 0 }) }}
                  </span>
                </div>

                <el-table :data="visibleFiles" size="small" max-height="480" :empty-text="t('upgrade.noFiles')">
                  <el-table-column prop="path" :label="t('upgrade.colFile')" min-width="360">
                    <template #default="{ row }">
                      <span class="mono path">{{ row.path }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column :label="t('upgrade.colKind')" width="150">
                    <template #default="{ row }">
                      <el-tag :type="tagType(row.kind)" size="small" effect="light">{{ row.kind_label }}</el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column :label="t('upgrade.colAdded')" width="90" align="right">
                    <template #default="{ row }">
                      <span v-if="row.added" class="added">+{{ row.added }}</span>
                      <span v-else class="muted">—</span>
                    </template>
                  </el-table-column>
                  <el-table-column :label="t('upgrade.colDeleted')" width="90" align="right">
                    <template #default="{ row }">
                      <span v-if="row.deleted" class="deleted">-{{ row.deleted }}</span>
                      <span v-else class="muted">—</span>
                    </template>
                  </el-table-column>
                </el-table>
              </el-tab-pane>

              <el-tab-pane :label="t('upgrade.commitsTab', { count: plan ? plan.commits.length : 0 })" name="commits">
                <el-timeline v-if="plan && plan.commits.length" class="timeline">
                  <el-timeline-item
                    v-for="c in plan.commits"
                    :key="c.hash"
                    :timestamp="c.date"
                    placement="top"
                    type="primary"
                    size="normal"
                  >
                    <div class="commit">
                      <el-tag size="small" effect="plain" class="mono">{{ c.short }}</el-tag>
                      <span class="commit-msg">{{ c.message }}</span>
                      <span class="commit-author">{{ c.author }}</span>
                    </div>
                  </el-timeline-item>
                </el-timeline>
                <el-empty v-else :description="t('upgrade.noCommits')" />
              </el-tab-pane>
            </el-tabs>
          </el-card>
        </template>
      </template>

      <div v-else class="loading-block">
        <el-skeleton :rows="4" animated />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:upgrade' })

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, Search } from '@element-plus/icons-vue'
import ArtIcon from '@/components/core/ArtIcon.vue'
import {
  upgradeCheck,
  upgradeInit,
  upgradeOverview,
  upgradeRun,
  upgradeTags,
  type UpgradeFile,
  type UpgradeOverview,
  type UpgradePlan,
  type UpgradeRunResult,
} from '@/api/upgrade'

const { t } = useI18n({ useScope: 'global' })

const overview = ref<UpgradeOverview | null>(null)
const plan = ref<UpgradePlan | null>(null)
const runResult = ref<UpgradeRunResult | null>(null)
const tags = ref<string[]>([])

const source = ref('')

/**
 * 目标版本。`__track__` 是哨兵值，表示「用配置里的跟踪分支」。
 * 不用空字符串：el-select 会把 value='' 当成「未选中」，导致选项点了没反应。
 */
const TRACK_VALUE = '__track__'
const targetRef = ref(TRACK_VALUE)
const force = ref(false)
const tab = ref('files')
const kindFilter = ref<'changed' | 'all' | 'kept'>('changed')

const checking = ref(false)
const running = ref(false)
const initing = ref(false)

/** 会被上游改动的分类 */
const CHANGED = ['safe', 'new', 'conflict', 'removed']
/** 仅本地保留的分类 */
const KEPT = ['local', 'deleted']

const visibleFiles = computed<UpgradeFile[]>(() => {
  const files = plan.value?.files || []
  if (kindFilter.value === 'all') {
    return files
  }
  const allow = kindFilter.value === 'changed' ? CHANGED : KEPT
  return files.filter((f) => allow.includes(f.kind))
})

/** 把哨兵值还原成「不指定版本」（undefined = 用跟踪分支） */
function effectiveRef(): string | undefined {
  return targetRef.value === TRACK_VALUE ? undefined : targetRef.value
}

function short(hash: string): string {
  return (hash || '').slice(0, 8)
}

function tagType(kind: string): 'success' | 'primary' | 'warning' | 'danger' | 'info' {
  switch (kind) {
    case 'safe':
      return 'success'
    case 'new':
      return 'primary'
    case 'conflict':
      return 'danger'
    case 'removed':
      return 'warning'
    default:
      return 'info'
  }
}

async function loadOverview(): Promise<void> {
  overview.value = await upgradeOverview()
  if (!source.value) {
    source.value = overview.value.default_source
  }
  if (source.value) {
    await loadTags()
  }
}

async function loadTags(): Promise<void> {
  try {
    const result = await upgradeTags(source.value)
    tags.value = result.tags
  } catch {
    tags.value = []
  }
}

async function onSourceChange(): Promise<void> {
  plan.value = null
  targetRef.value = TRACK_VALUE
  await loadTags()
  await onCheck()
}

async function onCheck(): Promise<void> {
  if (!overview.value?.initialized) {
    await loadOverview()
    return
  }

  checking.value = true
  try {
    plan.value = await upgradeCheck(source.value, effectiveRef())
    force.value = false
  } finally {
    checking.value = false
  }
}

async function onInit(): Promise<void> {
  await ElMessageBox.confirm(
    t('upgrade.initConfirm', {
      source: sourceLabel.value,
      base: overview.value?.default_base || t('upgrade.defaultVersion'),
    }),
    t('upgrade.initTitle'),
    { type: 'info' },
  )

  initing.value = true
  try {
    const result = await upgradeInit(source.value)
    ElMessage.success(
      t(result.fallback ? 'upgrade.initSuccessFallback' : 'upgrade.initSuccess', {
        ref: result.ref,
        fallback: result.fallback ?? '',
        modified: result.modified,
        localOnly: result.local_only,
      }),
    )
    await loadOverview()
    await onCheck()
  } finally {
    initing.value = false
  }
}

async function onRun(): Promise<void> {
  const current = plan.value
  if (!current) {
    return
  }

  const willWrite = (current.summary.safe || 0) + (current.summary.new || 0) + (force.value ? current.pending : 0)
  const willRemove = current.summary.removed || 0

  await ElMessageBox.confirm(
    [
      t('upgrade.runConfirmRef', { ref: current.ref, commit: short(current.commit) }),
      willRemove
        ? t('upgrade.runConfirmWriteRemove', { write: willWrite, remove: willRemove })
        : t('upgrade.runConfirmWrite', { count: willWrite }),
      force.value && current.pending ? t('upgrade.runConfirmConflict', { count: current.pending }) : '',
      t('upgrade.runConfirmBackup'),
    ]
      .filter(Boolean)
      .join('\n'),
    t('upgrade.runTitle'),
    { type: 'warning' },
  )

  running.value = true
  try {
    runResult.value = await upgradeRun({
      source: source.value,
      ref: effectiveRef(),
      force: force.value,
      prune: false,
    })
    ElMessage.success(t('upgrade.runSuccess'))
    await loadOverview()
    await onCheck()
  } finally {
    running.value = false
  }
}

const sourceLabel = computed(() => overview.value?.sources.find((s) => s.key === source.value)?.label || source.value)

onMounted(async () => {
  await loadOverview()
  if (overview.value?.initialized) {
    await onCheck()
  }
})
</script>

<style scoped>
.block {
  margin-bottom: 14px;
}

.loading-block {
  padding: 24px;
  background: var(--art-card-bg);
  border-radius: var(--art-radius);
}

/* ---- 版本概览 ---- */
.version {
  display: flex;
  align-items: center;
  gap: 18px;
}

.version-side {
  display: flex;
  flex: 1;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.version-badge {
  flex-shrink: 0;
  padding: 3px 10px;
  font-size: 12px;
  border-radius: 999px;
}

.version-badge.current {
  color: var(--art-sub);
  background: var(--art-hover-bg);
}

.version-badge.target {
  color: #fff;
  background: var(--art-primary);
}

.version-badge.idle {
  color: var(--art-muted);
  background: var(--art-hover-bg);
}

.version-body {
  min-width: 0;
}

.version-name {
  font-size: 18px;
  font-weight: 600;
  color: var(--art-main);
}

.version-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.version-arrow {
  flex-shrink: 0;
  color: var(--art-gray-500);
}

/* ---- 统计 ---- */
.stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  padding-top: 16px;
  margin-top: 18px;
  border-top: 1px solid var(--art-card-border);
}

.stat-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
  color: var(--art-main);
}

.stat-label {
  margin-top: 2px;
  font-size: 12px;
  color: var(--art-muted);
}

/* ---- 操作栏 ---- */
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-items: center;
  justify-content: space-between;
}

.filters,
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.filter-label {
  font-size: 13px;
  color: var(--art-sub);
}

.force-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  padding-top: 12px;
  margin-top: 14px;
  border-top: 1px dashed var(--art-card-border);
}

.force-hint {
  font-size: 12px;
  color: var(--art-muted);
}

.result {
  margin-top: 14px;
}

.result :deep(.el-alert__content) {
  font-size: 13px;
}

.result :deep(.el-alert__content div) {
  margin-top: 2px;
}

.table-tools {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.tip-inline {
  font-size: 12px;
  color: var(--art-muted);
}

.timeline {
  padding-top: 4px;
}

.commit {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.commit-msg {
  color: var(--art-main);
}

.commit-author {
  font-size: 12px;
  color: var(--art-muted);
}

.tip {
  margin: 0;
  font-size: 12px;
  color: var(--art-muted);
  text-align: center;
}

/* ---- 语义色（只用 token） ---- */
.mono {
  font-family: var(--art-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  font-size: 12px;
}

.path {
  color: var(--art-main);
  word-break: break-all;
}

.added {
  color: var(--art-success);
}

.deleted {
  color: var(--art-danger);
}

.success {
  color: var(--art-success);
}

.primary {
  color: var(--art-primary);
}

.danger {
  color: var(--art-danger);
}

.muted {
  color: var(--art-muted);
}

.danger-text {
  color: var(--art-danger);
}

@media (max-width: 768px) {
  .version {
    flex-direction: column;
    align-items: flex-start;
  }

  .version-arrow {
    display: none;
  }

  .stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
