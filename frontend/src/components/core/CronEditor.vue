<template>
  <div class="cron-editor">
    <!-- 执行周期 + 具体时间（宝塔式：选多久一次，再填几点几分） -->
    <div class="cron-row">
      <el-select v-model="cycle" class="cron-cycle">
        <el-option v-for="c in CYCLES" :key="c.value" :label="c.label" :value="c.value" />
      </el-select>

      <!-- 每 N 秒 / 每 N 分钟 / 每 N 小时 -->
      <template v-if="cycle === 'every_n_seconds' || cycle === 'every_n_minutes' || cycle === 'every_n_hours'">
        <span class="cron-sep">每</span>
        <el-input-number v-model="state.n" :min="1" :max="nMax" size="small" controls-position="right" />
        <span class="cron-sep">{{ intervalUnit }}执行一次</span>
      </template>

      <span v-else-if="cycle === 'every_second'" class="cron-sep">每秒都执行一次</span>

      <!-- 每天 / 每周 / 每月 -->
      <template v-else-if="cycle === 'daily' || cycle === 'weekly' || cycle === 'monthly'">
        <template v-if="cycle === 'weekly'">
          <span class="cron-sep">每</span>
          <el-select v-model="state.week" size="small" class="cron-week">
            <el-option v-for="(w, i) in WEEK_LABELS" :key="i" :label="w" :value="i" />
          </el-select>
          <span class="cron-sep">的</span>
        </template>
        <template v-else-if="cycle === 'monthly'">
          <span class="cron-sep">每月</span>
          <el-input-number v-model="state.day" :min="1" :max="31" size="small" controls-position="right" />
          <span class="cron-sep">号的</span>
        </template>
        <span v-else class="cron-sep">每天</span>

        <el-input-number v-model="state.hour" :min="0" :max="23" size="small" controls-position="right" />
        <span class="cron-sep">时</span>
        <el-input-number v-model="state.minute" :min="0" :max="59" size="small" controls-position="right" />
        <span class="cron-sep">分</span>
        <el-input-number v-model="state.second" :min="0" :max="59" size="small" controls-position="right" />
        <span class="cron-sep">秒</span>
      </template>

      <!-- 自定义 -->
      <el-input v-else v-model="expression" class="cron-custom" placeholder="六段：秒 分 时 日 月 周，如 0 0 2 * * *" />
    </div>

    <div class="cron-result">
      <div class="cron-result-row">
        <span class="cron-result-label">表达式</span>
        <code class="cron-expr">{{ expression }}</code>
      </div>
      <div class="cron-result-row">
        <span class="cron-result-label">说明</span>
        <span class="cron-desc">{{ description }}</span>
      </div>
      <div class="cron-result-row">
        <span class="cron-result-label">最近运行</span>
        <span v-if="previews.length" class="cron-previews">
          <span v-for="t in previews" :key="t" class="cron-preview">{{ t }}</span>
        </span>
        <span v-else class="cron-desc">无法预览（表达式不完整）</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import {
  buildFromCycle,
  detectCycle,
  formatCronTime,
  nextRunTimes,
  type CronCycle,
  type CronCycleState,
} from '@/utils/cron'

/**
 * Cron 表达式可视化编辑器（宝塔面板风格）。
 *
 * 不要求懂表达式：先选「执行周期」（每秒 / 每 N 秒 / 每 N 分钟 / 每 N 小时 /
 * 每天 / 每周 / 每月），再补具体时间（几点几分 / 几号 / 星期几）。
 * 真的需要复杂规则（如每周一三五）时，切「自定义表达式」直接写六段。
 *
 * 底部固定回显：生成的表达式 + 中文说明 + 最近 3 次运行时间。
 * 编辑老任务 / 点预设时会反向识别成对应周期（能识别的才切，其余进「自定义」）。
 */

const CYCLES: Array<{ value: CronCycle; label: string }> = [
  { value: 'every_second', label: '每秒' },
  { value: 'every_n_seconds', label: '每 N 秒' },
  { value: 'every_n_minutes', label: '每 N 分钟' },
  { value: 'every_n_hours', label: '每 N 小时' },
  { value: 'daily', label: '每天' },
  { value: 'weekly', label: '每周' },
  { value: 'monthly', label: '每月' },
  { value: 'custom', label: '自定义表达式' },
]

const WEEK_LABELS = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']

const expression = defineModel<string>({ required: true })

/** 周期 + 具体时间（buildFromCycle / detectCycle 的可变副本） */
const state = reactive<CronCycleState>({ cycle: 'daily', n: 10, hour: 2, minute: 0, second: 0, day: 1, week: 1 })

const cycle = computed<CronCycle>({
  get: () => state.cycle,
  set: (v) => {
    state.cycle = v
  },
})

const intervalUnit = computed(() =>
  state.cycle === 'every_n_seconds' ? '秒' : state.cycle === 'every_n_minutes' ? '分钟' : '小时',
)
const nMax = computed(() => (state.cycle === 'every_n_hours' ? 23 : 59))

/* ---- 周期 + 时间 → 表达式（custom 直通，不经过 buildFromCycle） ---- */

const built = computed(() => (state.cycle === 'custom' ? expression.value : buildFromCycle(state)))

/* ---- 说明与预览 ---- */

const pad = (v: number): string => String(v).padStart(2, '0')

const description = computed(() => {
  switch (state.cycle) {
    case 'every_second':
      return '每秒执行一次'
    case 'every_n_seconds':
      return `每 ${state.n} 秒执行一次`
    case 'every_n_minutes':
      return `每 ${state.n} 分钟执行一次`
    case 'every_n_hours':
      return `每 ${state.n} 小时执行一次`
    case 'daily':
      return `每天 ${pad(state.hour)}:${pad(state.minute)}:${pad(state.second)} 执行`
    case 'weekly':
      return `每${WEEK_LABELS[state.week]} ${pad(state.hour)}:${pad(state.minute)}:${pad(state.second)} 执行`
    case 'monthly':
      return `每月 ${state.day} 号 ${pad(state.hour)}:${pad(state.minute)}:${pad(state.second)} 执行`
    case 'custom':
      return '自定义表达式（六段：秒 分 时 日 月 周）'
    default:
      // 兜底：cycle 是联合类型，理论上不会走到这里
      return ''
  }
})

const previews = computed(() => {
  try {
    return nextRunTimes(expression.value, 3).map(formatCronTime)
  } catch {
    return []
  }
})

/* ---- 双向同步 ---- */

// 周期 / 时间变了 → 非自定义模式下重新生成表达式
watch(built, (v) => {
  if (state.cycle !== 'custom') {
    expression.value = v
  }
})

// 外部值变化（编辑回显 / 点预设）→ 反向识别周期；自己刚生成的值、以及自定义编辑中都不回灌
watch(
  expression,
  (v) => {
    if (v === built.value) {
      return
    }
    Object.assign(state, detectCycle(v ?? ''))
  },
  { immediate: true },
)
</script>

<style scoped>
.cron-editor {
  width: 100%;
}

.cron-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.cron-cycle {
  width: 160px;
  flex-shrink: 0;
}

.cron-week {
  width: 96px;
}

.cron-custom {
  flex: 1;
  min-width: 240px;
}

.cron-sep {
  font-size: 13px;
  color: var(--art-sub);
  white-space: nowrap;
}

.cron-row :deep(.el-input-number) {
  width: 92px;
}

.cron-result {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  margin-top: 12px;
  background: var(--art-primary-light, var(--el-color-primary-light-9));
  border-radius: 6px;
}

.cron-result-row {
  display: flex;
  gap: 10px;
  align-items: baseline;
}

.cron-result-label {
  flex-shrink: 0;
  width: 56px;
  font-size: 12px;
  color: var(--art-muted);
}

.cron-expr {
  font-family: Consolas, Monaco, monospace;
  font-size: 14px;
  font-weight: 600;
  color: var(--art-main);
}

.cron-desc {
  font-size: 12px;
  color: var(--art-sub);
}

.cron-previews {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
}

.cron-preview {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-sub);
}
</style>
