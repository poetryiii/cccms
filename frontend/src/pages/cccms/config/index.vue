<template>
  <div class="art-fill">
    <el-card class="cfg" shadow="never">
      <template #header>
        <div class="cfg-head">
          <div class="cfg-head-left">
            <span class="cfg-title">系统配置</span>
            <el-tag v-if="dirtyCount" type="warning" effect="light" size="small">
              {{ dirtyCount }} 项未保存
            </el-tag>
            <span v-else class="cfg-count">共 {{ items.length }} 项</span>
          </div>

          <div class="cfg-head-right">
            <el-input
              v-model="keyword"
              placeholder="搜索配置项 / 键名"
              clearable
              :prefix-icon="Search"
              class="cfg-search"
            />
            <el-button :icon="Refresh" @click="reload">刷新</el-button>
            <el-button :icon="RefreshLeft" :disabled="!dirtyCount" @click="discard">放弃修改</el-button>
            <el-button
              v-auth="'cccms:config:save'"
              type="primary"
              :icon="Check"
              :loading="saving"
              :disabled="!dirtyCount"
              @click="onSave"
            >
              保存{{ dirtyCount ? `（${dirtyCount}）` : '' }}
            </el-button>
          </div>
        </div>
      </template>

      <div class="cfg-body">
        <!-- 分组导航 -->
        <aside class="cfg-aside">
          <ul class="cfg-groups">
            <li :class="{ 'is-active': group === '' }" @click="group = ''">
              <span>全部</span>
              <em>{{ filtered.length }}</em>
            </li>
            <li
              v-for="g in groups"
              :key="g.name"
              :class="{ 'is-active': group === g.name }"
              @click="group = g.name"
            >
              <span>{{ g.name }}</span>
              <em>{{ g.count }}</em>
            </li>
          </ul>
        </aside>

        <!-- 表单 -->
        <div class="cfg-main">
          <el-skeleton v-if="loading" :rows="8" animated />

          <el-empty
            v-else-if="!visibleGroups.length"
            :description="keyword ? '没有匹配的配置项' : '暂无配置项'"
          />

          <template v-else>
            <section v-for="g in visibleGroups" :key="g.name" class="cfg-section">
              <h3 class="cfg-section-title">{{ g.name }}</h3>

              <el-form label-width="180px" label-position="right">
                <el-form-item
                  v-for="item in g.items"
                  :key="item.name"
                  :class="{ 'is-dirty': isDirty(item.name) }"
                >
                  <template #label>
                    <div class="cfg-label">
                      <span class="cfg-label-text">{{ item.title || item.name }}</span>
                      <button type="button" class="cfg-label-key" @click="copyKey(item.name)">
                        {{ item.name }}
                      </button>
                    </div>
                  </template>

                  <div class="cfg-field">
                    <el-switch
                      v-if="item.type === 'switch'"
                      v-model="values[item.name]"
                      :active-value="1"
                      :inactive-value="0"
                    />

                    <el-radio-group
                      v-else-if="item.type === 'radio'"
                      v-model="values[item.name]"
                    >
                      <el-radio
                        v-for="opt in parseOptions(item)"
                        :key="String(opt.value)"
                        :value="opt.value"
                      >
                        {{ opt.label }}
                      </el-radio>
                    </el-radio-group>

                    <el-select
                      v-else-if="item.type === 'select'"
                      v-model="values[item.name]"
                      placeholder="请选择"
                      style="width: 260px"
                    >
                      <el-option
                        v-for="opt in parseOptions(item)"
                        :key="String(opt.value)"
                        :label="opt.label"
                        :value="opt.value"
                      />
                    </el-select>

                    <el-input-number
                      v-else-if="item.type === 'input-number'"
                      v-model="values[item.name]"
                      :min="0"
                      controls-position="right"
                      style="width: 200px"
                    />

                    <el-input
                      v-else-if="item.type === 'textarea'"
                      v-model="values[item.name]"
                      type="textarea"
                      :autosize="{ minRows: 2, maxRows: 6 }"
                      style="max-width: 560px"
                    />

                    <el-input
                      v-else
                      v-model="values[item.name]"
                      placeholder="请输入"
                      style="max-width: 420px"
                    />

                    <div v-if="item.remark" class="cfg-remark">{{ item.remark }}</div>
                  </div>
                </el-form-item>
              </el-form>
            </section>
          </template>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:config' })

import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check, Refresh, RefreshLeft, Search } from '@element-plus/icons-vue'
import { configList, configSave, type ConfigItem, type ConfigOption } from '@/api/config'

const UNGROUPED = '未分组'

const loading = ref(false)
const saving = ref(false)
const items = ref<ConfigItem[]>([])
const keyword = ref('')
const group = ref('')

/** 表单当前值 */
const values = reactive<Record<string, any>>({})
/** 加载时的快照，用于判断哪些项被改过 */
const original = ref<Record<string, any>>({})

/* ---------- 取值与比较 ---------- */

function normalize(item: ConfigItem): unknown {
  const raw = item.value ?? ''
  if (item.type === 'switch') {
    return Number(raw) === 1 ? 1 : 0
  }
  if (item.type === 'input-number') {
    const num = Number(raw)
    return Number.isFinite(num) ? num : 0
  }
  return raw
}

function parseOptions(item: ConfigItem): ConfigOption[] {
  const raw = item.options
  if (!raw) {
    return []
  }
  if (Array.isArray(raw)) {
    return raw as ConfigOption[]
  }
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw)
      return Array.isArray(parsed) ? (parsed as ConfigOption[]) : []
    } catch {
      return []
    }
  }
  return []
}

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

function isDirty(name: string): boolean {
  return !same(values[name], original.value[name])
}

const dirtyKeys = computed(() => Object.keys(values).filter((key) => isDirty(key)))
const dirtyCount = computed(() => dirtyKeys.value.length)

/* ---------- 过滤与分组 ---------- */

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) {
    return items.value
  }
  return items.value.filter((item) =>
    [item.title, item.name, item.remark].some((text) => (text || '').toLowerCase().includes(kw)),
  )
})

const groups = computed(() => {
  const counter = new Map<string, number>()
  for (const item of filtered.value) {
    const name = item.group || UNGROUPED
    counter.set(name, (counter.get(name) ?? 0) + 1)
  }
  return Array.from(counter, ([name, count]) => ({ name, count }))
})

const visibleGroups = computed(() => {
  const list = group.value
    ? filtered.value.filter((item) => (item.group || UNGROUPED) === group.value)
    : filtered.value

  const bucket = new Map<string, ConfigItem[]>()
  for (const item of list) {
    const name = item.group || UNGROUPED
    const arr = bucket.get(name)
    if (arr) {
      arr.push(item)
    } else {
      bucket.set(name, [item])
    }
  }
  return Array.from(bucket, ([name, groupItems]) => ({ name, items: groupItems }))
})

/* ---------- 加载与保存 ---------- */

async function reload(): Promise<void> {
  loading.value = true
  try {
    const list = await configList()
    items.value = list

    const next: Record<string, any> = {}
    for (const item of list) {
      next[item.name] = normalize(item)
    }

    // 清掉已删除的键，再覆盖当前值
    Object.keys(values).forEach((key) => {
      if (!(key in next)) {
        delete values[key]
      }
    })
    Object.assign(values, next)
    original.value = JSON.parse(JSON.stringify(next))
  } finally {
    loading.value = false
  }
}

function discard(): void {
  Object.assign(values, JSON.parse(JSON.stringify(original.value)))
  ElMessage.info('已放弃未保存的修改')
}

async function onSave(): Promise<void> {
  if (!dirtyCount.value) {
    return
  }
  // 只提交变更项，避免用陈旧值覆盖其他字段
  const payload: Record<string, unknown> = {}
  dirtyKeys.value.forEach((key) => {
    payload[key] = values[key]
  })

  saving.value = true
  try {
    const res = await configSave(payload)
    ElMessage.success(`已保存 ${res?.updated ?? dirtyCount.value} 项`)
    await reload()
  } finally {
    saving.value = false
  }
}

async function copyKey(name: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(name)
    ElMessage.success(`已复制：${name}`)
  } catch {
    ElMessage.warning('复制失败，请手动选择')
  }
}

/* ---------- 未保存提示 ---------- */

onBeforeRouteLeave(async () => {
  if (!dirtyCount.value) {
    return true
  }
  try {
    await ElMessageBox.confirm('有未保存的修改，确定离开吗？', '未保存的修改', {
      type: 'warning',
      confirmButtonText: '离开',
      cancelButtonText: '留在本页',
    })
    return true
  } catch {
    return false
  }
})

function handleBeforeUnload(event: BeforeUnloadEvent): void {
  if (dirtyCount.value) {
    event.preventDefault()
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', handleBeforeUnload)
  void reload()
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})
</script>

<style scoped>
.cfg {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.cfg :deep(.el-card__body) {
  display: flex;
  flex: 1;
  min-height: 0;
  padding: 0;
}

.cfg-head {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
}

.cfg-head-left {
  display: flex;
  gap: 10px;
  align-items: center;
}

.cfg-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--art-main);
}

.cfg-count {
  font-size: 12px;
  color: var(--art-muted);
}

.cfg-head-right {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.cfg-search {
  width: 220px;
}

.cfg-body {
  display: flex;
  flex: 1;
  min-height: 0;
}

/* ---- 左侧分组 ---- */
.cfg-aside {
  flex: 0 0 170px;
  padding: 10px;
  overflow-y: auto;
  border-right: 1px solid var(--art-card-border);
}

.cfg-groups {
  padding: 0;
  margin: 0;
  list-style: none;
}

.cfg-groups li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 36px;
  padding: 0 10px;
  margin-bottom: 2px;
  font-size: 13px;
  color: var(--art-sub);
  cursor: pointer;
  border-radius: calc(var(--art-radius) - 2px);
  transition: all 0.15s ease;
}

.cfg-groups li:hover {
  background: var(--art-hover-bg);
}

.cfg-groups li.is-active {
  font-weight: 600;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.cfg-groups em {
  font-size: 12px;
  font-style: normal;
  color: var(--art-muted);
}

/* ---- 右侧表单 ---- */
.cfg-main {
  flex: 1;
  min-width: 0;
  padding: 18px 24px 24px;
  overflow-y: auto;
}

.cfg-section + .cfg-section {
  margin-top: 8px;
}

.cfg-section-title {
  padding-left: 10px;
  margin: 18px 0 14px;
  font-size: 13px;
  font-weight: 600;
  color: var(--art-main);
  border-left: 3px solid var(--el-color-primary);
}

.cfg-label {
  display: flex;
  flex-direction: column;
  gap: 2px;
  line-height: 1.4;
}

.cfg-label-text {
  color: var(--art-main);
}

.cfg-label-key {
  padding: 0;
  font-family: Consolas, Monaco, monospace;
  font-size: 11px;
  color: var(--art-muted);
  cursor: pointer;
  background: transparent;
  border: none;
}

.cfg-label-key:hover {
  color: var(--el-color-primary);
  text-decoration: underline;
}

.cfg-field {
  width: 100%;
}

.cfg-remark {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}

/* 变更项左侧高亮 */
.cfg :deep(.el-form-item.is-dirty) {
  position: relative;
}

.cfg :deep(.el-form-item.is-dirty)::before {
  position: absolute;
  top: 8px;
  bottom: 8px;
  left: -12px;
  width: 3px;
  content: '';
  background: var(--el-color-warning);
  border-radius: 2px;
}

.cfg :deep(.el-form-item) {
  margin-bottom: 20px;
}
</style>
