<template>
  <div class="art-scroll">
    <div class="art-page">
      <!-- 环境不可用 -->
      <el-alert
        v-if="overview && !overview.enabled"
        type="warning"
        :closable="false"
        show-icon
        title="上游同步已关闭"
        description="在 plugin/cccms/config/upgrade.php 中把 enable 设为 true 后可用。"
      />
      <el-alert
        v-else-if="overview && !overview.git_available"
        type="error"
        :closable="false"
        show-icon
        title="未检测到 git"
        description="服务器上找不到 git 可执行文件，请在 plugin/cccms/config/upgrade.php 的 git 项里填写绝对路径。"
      />

      <template v-else-if="overview">
        <!-- 版本概览 -->
        <el-card shadow="never" class="block version-card">
          <div class="version">
            <div class="version-side">
              <div class="version-badge current">当前</div>
              <div class="version-body">
                <div class="version-name">{{ overview.current.base || '未建立基线' }}</div>
                <div class="version-meta">
                  <span v-if="overview.current.commit" class="mono">
                    {{ short(overview.current.commit) }}
                  </span>
                  <span v-if="overview.current.synced_at">{{ overview.current.synced_at }}</span>
                  <span v-if="overview.current.files">上游 {{ overview.current.files }} 个文件</span>
                </div>
              </div>
            </div>

            <div class="version-arrow">
              <el-icon :size="20"><ArtIcon name="icon-refresh-right" /></el-icon>
            </div>

            <div class="version-side">
              <div class="version-badge" :class="plan?.has_update ? 'target' : 'idle'">目标</div>
              <div class="version-body">
                <div class="version-name">
                  {{ plan ? plan.ref : overview.track }}
                </div>
                <div class="version-meta">
                  <span v-if="plan?.commit" class="mono">{{ short(plan.commit) }}</span>
                  <template v-if="plan">
                    <span v-if="!plan.has_update">已是最新</span>
                    <span v-else>{{ plan.commits.length }} 个提交</span>
                  </template>
                  <span v-else>尚未检查</span>
                </div>
              </div>
            </div>
          </div>

          <div v-if="plan" class="stats">
            <div class="stat">
              <div class="stat-value success">{{ plan.summary.safe || 0 }}</div>
              <div class="stat-label">可安全覆盖</div>
            </div>
            <div class="stat">
              <div class="stat-value primary">{{ plan.summary.new || 0 }}</div>
              <div class="stat-label">上游新增</div>
            </div>
            <div class="stat">
              <div class="stat-value" :class="plan.pending ? 'danger' : 'muted'">{{ plan.pending }}</div>
              <div class="stat-label">冲突待合并</div>
            </div>
            <div class="stat">
              <div class="stat-value">
                <span class="added">+{{ plan.lines.added }}</span>
                <span class="deleted">-{{ plan.lines.deleted }}</span>
              </div>
              <div class="stat-label">上游代码改动</div>
            </div>
          </div>
        </el-card>

        <!-- 未建立基线：引导 -->
        <el-card v-if="!overview.initialized" shadow="never" class="block">
          <el-empty description="尚未建立基线，无法判断本地相对上游改过哪些文件">
            <el-button v-auth="'cccms:upgrade:init'" type="primary" :loading="initing" @click="onInit">
              立即建立基线
            </el-button>
          </el-empty>
          <p class="tip">
            基线会记录「当前代码基于的上游版本」各文件的内容指纹，之后升级时据此判断
            哪些文件是本地改过的（绝不能覆盖），哪些可以直接更新。
          </p>
        </el-card>

        <template v-else>
          <!-- 操作栏 -->
          <el-card shadow="never" class="block">
            <div class="toolbar">
              <div class="filters">
                <span class="filter-label">同步源</span>
                <el-select v-model="source" style="width: 190px" @change="onSourceChange">
                  <el-option v-for="s in overview.sources" :key="s.key" :label="s.label" :value="s.key" />
                </el-select>

                <span class="filter-label">目标版本</span>
                <el-select v-model="targetRef" style="width: 190px" @change="onCheck">
                  <el-option :label="`跟踪分支 ${overview.track}`" :value="TRACK_VALUE" />
                  <el-option v-for="t in tags" :key="t" :label="t" :value="t" />
                </el-select>
              </div>

              <div class="actions">
                <el-button :loading="checking" :icon="Search" @click="onCheck"> 检查更新 </el-button>
                <el-button
                  v-auth="'cccms:upgrade:run'"
                  type="primary"
                  :icon="Refresh"
                  :loading="running"
                  :disabled="!plan"
                  @click="onRun"
                >
                  立即升级
                </el-button>
              </div>
            </div>

            <div v-if="plan && plan.pending > 0" class="force-row">
              <el-checkbox v-model="force">
                同时覆盖 {{ plan.pending }} 个冲突文件（本地也改过，覆盖前会自动备份）
              </el-checkbox>
              <span class="force-hint">不勾选时这些文件会被跳过，保持本地版本</span>
            </div>

            <el-alert
              v-if="runResult"
              class="result"
              :type="runResult.written || runResult.removed ? 'success' : 'info'"
              :closable="false"
              show-icon
            >
              <template #title>
                上次升级：写入 {{ runResult.written }}，删除 {{ runResult.removed }}，备份 {{ runResult.backed }}
              </template>
              <template #default>
                <div v-if="runResult.backup_dir" class="mono">备份目录：{{ runResult.backup_dir }}</div>
                <div v-if="runResult.skipped.length">跳过：{{ runResult.skipped.length }} 个冲突文件</div>
                <div v-if="runResult.maintenance_error" class="danger-text">
                  后置同步失败：{{ runResult.maintenance_error }}
                </div>
                <div v-if="runResult.need_reload" class="danger-text">
                  代码已更新，请重启服务（Linux：php start.php reload / Windows：重启 php windows.php）后生效
                </div>
              </template>
            </el-alert>
          </el-card>

          <!-- 变更明细 -->
          <el-card shadow="never" class="block">
            <el-tabs v-model="tab">
              <el-tab-pane :label="`变更文件（${plan ? plan.files.length : 0}）`" name="files">
                <div class="table-tools">
                  <el-radio-group v-model="kindFilter" size="small">
                    <el-radio-button value="changed">会被改动</el-radio-button>
                    <el-radio-button value="all">全部</el-radio-button>
                    <el-radio-button value="kept">仅本地保留</el-radio-button>
                  </el-radio-group>
                  <span class="tip-inline"> 本地独有文件 {{ plan?.local_only || 0 }} 个（业务插件，不会被改动） </span>
                </div>

                <el-table :data="visibleFiles" size="small" max-height="480" empty-text="没有需要展示的文件">
                  <el-table-column prop="path" label="文件" min-width="360">
                    <template #default="{ row }">
                      <span class="mono path">{{ row.path }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="分类" width="150">
                    <template #default="{ row }">
                      <el-tag :type="tagType(row.kind)" size="small" effect="light">{{ row.kind_label }}</el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="增加" width="90" align="right">
                    <template #default="{ row }">
                      <span v-if="row.added" class="added">+{{ row.added }}</span>
                      <span v-else class="muted">—</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="删除" width="90" align="right">
                    <template #default="{ row }">
                      <span v-if="row.deleted" class="deleted">-{{ row.deleted }}</span>
                      <span v-else class="muted">—</span>
                    </template>
                  </el-table-column>
                </el-table>
              </el-tab-pane>

              <el-tab-pane :label="`提交记录（${plan ? plan.commits.length : 0}）`" name="commits">
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
                <el-empty v-else description="没有新的提交记录" />
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
    `将以「${sourceLabel.value}」的 ${overview.value?.default_base || '默认版本'} 建立基线，用于识别本地改动。`,
    '建立基线',
    { type: 'info' },
  )

  initing.value = true
  try {
    const result = await upgradeInit(source.value)
    ElMessage.success(
      `基线已建立（${result.ref}${
        result.fallback ? `，因 ${result.fallback} 不存在而回退` : ''
      }）：本地已改 ${result.modified} 个，本地新增 ${result.local_only} 个`,
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
      `目标版本：${current.ref}（${short(current.commit)}）`,
      `将写入 / 覆盖 ${willWrite} 个文件${willRemove ? `，删除 ${willRemove} 个` : ''}`,
      force.value && current.pending ? `其中 ${current.pending} 个是本地也改过的冲突文件` : '',
      '覆盖前会自动备份，本地独有文件不受影响。',
    ]
      .filter(Boolean)
      .join('\n'),
    '确认升级',
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
    ElMessage.success('升级完成')
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
