<template>
  <div class="art-scroll">
    <div class="art-page">
      <!-- 欢迎 -->
      <el-card shadow="never" class="hello">
        <div class="hello-inner">
          <el-avatar :size="52" class="hello-avatar">{{ avatarText }}</el-avatar>
          <div class="hello-text">
            <h2 class="hello-title">{{ helloText }}</h2>
            <p class="hello-sub">
              <span>{{ todayText }}</span>
              <span class="hello-divider" />
              <span>{{ t('dashboard.roleLabel') }}</span>
              <el-tag v-for="r in userStore.profile?.roles || []" :key="r" size="small" effect="light">
                {{ r }}
              </el-tag>
              <el-tag v-if="userStore.superAdmin" size="small" type="danger" effect="light">
                {{ t('dashboard.superAdmin') }}
              </el-tag>
            </p>
          </div>
        </div>
      </el-card>

      <!-- 统计卡 -->
      <div class="cards">
        <el-card v-for="c in cards" :key="c.key" shadow="never" class="card">
          <div class="card-inner">
            <div class="card-icon" :style="{ background: c.bg, color: c.color }">
              <el-icon :size="22"><ArtIcon :name="c.icon" /></el-icon>
            </div>
            <div>
              <div class="card-value">{{ c.value }}</div>
              <div class="card-label">{{ c.label }}</div>
            </div>
          </div>
        </el-card>
      </div>

      <!-- 图表 -->
      <el-row :gutter="14">
        <el-col :xs="24" :lg="15">
          <el-card shadow="never" class="chart-card">
            <template #header
              ><span>{{ t('dashboard.trendTitle') }}</span></template
            >
            <div ref="trendRef" class="chart" />
          </el-card>
        </el-col>
        <el-col :xs="24" :lg="9">
          <el-card shadow="never" class="chart-card">
            <template #header
              ><span>{{ t('dashboard.pieTitle') }}</span></template
            >
            <div ref="pieRef" class="chart" />
          </el-card>
        </el-col>
      </el-row>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'dashboard' })

import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as echarts from 'echarts/core'
import { LineChart, PieChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import ArtIcon from '@/components/core/ArtIcon.vue'
import { dashboardStats, type DashboardStats } from '@/api/dashboard'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'

echarts.use([LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer])

const { t, locale } = useI18n({ useScope: 'global' })

const userStore = useUserStore()
const setting = useSettingStore()

const stats = ref<DashboardStats | null>(null)
const trendRef = ref<HTMLElement>()
const pieRef = ref<HTMLElement>()

let trendChart: echarts.ECharts | null = null
let pieChart: echarts.ECharts | null = null

const avatarText = computed(() => (userStore.nickname || 'U').charAt(0).toUpperCase())

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 6) {
    return t('dashboard.greetingNight')
  }
  if (hour < 12) {
    return t('dashboard.greetingMorning')
  }
  if (hour < 18) {
    return t('dashboard.greetingAfternoon')
  }
  return t('dashboard.greetingEvening')
})

// 称谓与问候语拼成整句交给语言包（不同语言的标点与语序不同）
const helloText = computed(() =>
  t('dashboard.helloTitle', { greeting: greeting.value, name: userStore.nickname || t('dashboard.admin') }),
)

const todayText = computed(() => {
  const now = new Date()
  const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return t('dashboard.dateText', { date, week: t(`dashboard.weekday${now.getDay()}`) })
})

const cards = computed(() => {
  const c = stats.value?.counts
  return [
    {
      key: 'user',
      label: t('dashboard.statUser'),
      value: c?.user ?? 0,
      icon: 'icon-user',
      color: '#2b6cff',
      bg: 'rgb(43 108 255 / 12%)',
    },
    {
      key: 'role',
      label: t('dashboard.statRole'),
      value: c?.role ?? 0,
      icon: 'icon-safe',
      color: '#722ed1',
      bg: 'rgb(114 46 209 / 12%)',
    },
    {
      key: 'dept',
      label: t('dashboard.statDept'),
      value: c?.dept ?? 0,
      icon: 'icon-tree',
      color: '#13c2c2',
      bg: 'rgb(19 194 194 / 12%)',
    },
    {
      key: 'post',
      label: t('dashboard.statPost'),
      value: c?.post ?? 0,
      icon: 'icon-badge',
      color: '#fa8c16',
      bg: 'rgb(250 140 22 / 14%)',
    },
    {
      key: 'file',
      label: t('dashboard.statFile'),
      value: c?.file ?? 0,
      icon: 'icon-upload',
      color: '#21c26b',
      bg: 'rgb(33 194 107 / 12%)',
    },
    {
      key: 'today_log',
      label: t('dashboard.statTodayLog'),
      value: c?.today_log ?? 0,
      icon: 'icon-file',
      color: '#f4524d',
      bg: 'rgb(244 82 77 / 12%)',
    },
  ]
})

function cssVar(name: string, fallback: string): string {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

function renderTrend(): void {
  if (!trendRef.value || !stats.value) {
    return
  }
  trendChart ??= echarts.init(trendRef.value)

  const primary = cssVar('--art-primary', '#2b6cff')
  const line = cssVar('--art-card-border', '#e9edf5')
  const sub = cssVar('--art-sub', '#5b6474')

  trendChart.setOption({
    grid: { left: 6, right: 18, top: 22, bottom: 4, containLabel: true },
    tooltip: { trigger: 'axis' },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: stats.value.log_trend.dates.map((d) => d.slice(5)),
      axisLine: { lineStyle: { color: line } },
      axisTick: { show: false },
      axisLabel: { color: sub },
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      splitLine: { lineStyle: { color: line, type: 'dashed' } },
      axisLabel: { color: sub },
    },
    series: [
      {
        name: t('dashboard.trendSeries'),
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 7,
        data: stats.value.log_trend.values,
        itemStyle: { color: primary },
        lineStyle: { width: 3, color: primary },
        areaStyle: {
          color: {
            type: 'linear',
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
              { offset: 0, color: `${primary}4d` },
              { offset: 1, color: `${primary}00` },
            ],
          },
        },
      },
    ],
  })
}

function renderPie(): void {
  if (!pieRef.value || !stats.value) {
    return
  }
  pieChart ??= echarts.init(pieRef.value)

  const sub = cssVar('--art-sub', '#5b6474')
  const cardBg = cssVar('--art-card-bg', '#ffffff')
  const types = stats.value.file_types

  pieChart.setOption({
    tooltip: { trigger: 'item' },
    legend: { bottom: 0, icon: 'circle', textStyle: { color: sub } },
    series: [
      {
        type: 'pie',
        radius: ['46%', '68%'],
        center: ['50%', '44%'],
        avoidLabelOverlap: true,
        itemStyle: { borderColor: cardBg, borderWidth: 2 },
        label: { show: false },
        data: types.length ? types : [{ name: t('table.empty'), value: 1 }],
      },
    ],
  })
}

function renderAll(): void {
  renderTrend()
  renderPie()
}

function resizeAll(): void {
  trendChart?.resize()
  pieChart?.resize()
}

async function load(): Promise<void> {
  stats.value = await dashboardStats()
  await nextTick()
  renderAll()
}

// 主题或语言切换后图表文案 / 配色要跟着变（echarts 是命令式渲染，需手动重绘）
watch([() => setting.isDark, locale], async () => {
  await nextTick()
  renderAll()
})

onMounted(async () => {
  window.addEventListener('resize', resizeAll)
  await load()
})

// keep-alive 缓存后重新进入，容器尺寸可能已变
onActivated(() => {
  nextTick(resizeAll)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeAll)
  trendChart?.dispose()
  pieChart?.dispose()
  trendChart = null
  pieChart = null
})
</script>

<style scoped>
.hello {
  margin-bottom: 14px;
}

.hello-inner {
  display: flex;
  gap: 16px;
  align-items: center;
}

.hello-avatar {
  font-size: 20px;
  background: var(--art-primary);
}

.hello-title {
  margin: 0;
  font-size: 19px;
  font-weight: 600;
  color: var(--art-main);
}

.hello-sub {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin: 8px 0 0;
  font-size: 13px;
  color: var(--art-sub);
}

.hello-divider {
  width: 1px;
  height: 12px;
  background: var(--art-card-border);
}

.cards {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.card-inner {
  display: flex;
  gap: 12px;
  align-items: center;
}

.card-icon {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 46px;
  border-radius: calc(var(--art-radius) - 2px);
}

.card-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
  color: var(--art-main);
}

.card-label {
  font-size: 12px;
  color: var(--art-muted);
}

.chart-card :deep(.el-card__body) {
  padding: 8px 12px 12px;
}

.chart {
  width: 100%;
  height: 288px;
}

@media (max-width: 1400px) {
  .cards {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .cards {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .chart {
    height: 240px;
  }
}
</style>
