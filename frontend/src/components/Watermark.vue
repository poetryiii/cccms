<template>
  <!-- 全局水印：固定铺满视口、不拦截任何交互（pointer-events: none） -->
  <div v-if="active" class="watermark" :style="watermarkStyle" />
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'

/**
 * 全局水印（后台「配置管理 → 水印」）。
 *
 * 实现方式：用 canvas 画一张「单个水印 + 间距」的平铺图，转成 dataURL 交给
 * `background-repeat: repeat` 铺满整个视口 —— 比按屏幕尺寸铺 N 个 DOM 节点更省。
 *
 * 支持的内容变量（大小写不敏感）：
 *   - `{username}` 当前用户昵称（无昵称时回落账号）
 *   - `{user_id}`  当前用户 ID
 *   - `{time}`     当前时间 `YYYY-MM-DD HH:mm`（按分钟自动刷新）
 *   - `{newline}`  换行（字面量 `\n` 同样按换行处理；也可直接回车换行）
 *
 * 只读取后台下发的渲染参数；用户名 / ID 由前端按当前登录用户代入，后端不下发隐私数据。
 */

/** 内容含 `{time}` 时的重绘间隔（毫秒）—— 让水印上的时间跟着走 */
const TIME_REFRESH_MS = 60_000

const appStore = useAppStore()
const userStore = useUserStore()

/** 生成好的水印平铺图（dataURL）；为空表示不渲染 */
const tile = ref('')

const cfg = computed(() => appStore.watermark)
const active = computed(() => cfg.value.enabled && tile.value !== '')
const watermarkStyle = computed(() => ({ backgroundImage: `url("${tile.value}")` }))

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

/** `{time}` 变量的取值：`YYYY-MM-DD HH:mm` */
function timeText(): string {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** 把配置的内容模板渲染成若干行文本（变量替换 + 换行拆分） */
function renderLines(): string[] {
  const replaced = (cfg.value.content || '')
    // 字面量 \n 与 {newline} 都当作换行
    .replace(/\\n/g, '\n')
    .replace(/\{newline\}/gi, '\n')
    .replace(/\{time\}/gi, timeText())
    .replace(/\{username\}/gi, userStore.nickname || '')
    .replace(/\{user_id\}/gi, String(userStore.profile?.id ?? ''))

  return replaced.split(/\r?\n/)
}

/** 画平铺图：先量文字，再算旋转后的包围盒，平铺块取「包围盒与配置间距的较大值」，保证不裁切 */
function buildTile(): void {
  if (!cfg.value.enabled) {
    tile.value = ''
    return
  }

  const lines = renderLines()
  const fontSize = Math.min(200, Math.max(8, Number(cfg.value.font_size) || 14))
  const gapX = Math.max(40, Number(cfg.value.gap_x) || 180)
  const gapY = Math.max(40, Number(cfg.value.gap_y) || 140)
  const opacity = Math.min(100, Math.max(1, Number(cfg.value.opacity) || 12)) / 100
  const angle = ((Number(cfg.value.angle) || 0) * Math.PI) / 180
  const lineHeight = fontSize * 1.4

  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) {
    return
  }

  ctx.font = `${fontSize}px sans-serif`
  const textWidth = Math.max(...lines.map((line) => ctx.measureText(line).width), 1)
  const textHeight = lineHeight * lines.length
  const cos = Math.abs(Math.cos(angle))
  const sin = Math.abs(Math.sin(angle))
  const boxWidth = textWidth * cos + textHeight * sin
  const boxHeight = textWidth * sin + textHeight * cos

  const width = Math.max(Math.ceil(boxWidth), gapX)
  const height = Math.max(Math.ceil(boxHeight), gapY)
  canvas.width = width
  canvas.height = height

  const c = canvas.getContext('2d')
  if (!c) {
    return
  }
  c.clearRect(0, 0, width, height)
  c.translate(width / 2, height / 2)
  c.rotate(angle)
  c.font = `${fontSize}px sans-serif`
  c.fillStyle = cfg.value.color || '#8c8c8c'
  c.globalAlpha = opacity
  c.textAlign = 'center'
  c.textBaseline = 'middle'
  const top = -((lines.length - 1) / 2) * lineHeight
  lines.forEach((line, index) => {
    c.fillText(line, 0, top + index * lineHeight)
  })

  tile.value = canvas.toDataURL('image/png')
}

/* ---- 内容含 {time} 时按分钟重绘 ---- */
let timer: number | null = null

function syncTimer(): void {
  if (timer !== null) {
    window.clearInterval(timer)
    timer = null
  }
  if (cfg.value.enabled && /\{time\}/i.test(cfg.value.content || '')) {
    timer = window.setInterval(buildTile, TIME_REFRESH_MS)
  }
}

watch(
  cfg,
  () => {
    buildTile()
    syncTimer()
  },
  { immediate: true, deep: true },
)

onBeforeUnmount(() => {
  if (timer !== null) {
    window.clearInterval(timer)
    timer = null
  }
})
</script>

<style scoped>
.watermark {
  position: fixed;
  inset: 0;
  /* 盖在页面内容之上（含弹窗 / 消息；Element 弹层 z-index 由 ElConfigProvider 从 3000 起递增） */
  z-index: 9999;
  pointer-events: none;
  background-repeat: repeat;
}
</style>
