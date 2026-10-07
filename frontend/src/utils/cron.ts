/**
 * Cron 预览（仅前端展示用，真正调度以后端 `CronMatcher` 为准）。
 *
 * 这里刻意复制了一份匹配逻辑：调度在 PHP 侧（常驻进程），
 * 而「最近运行时间」需要在编辑时即时反馈，来回发请求会让面板卡顿。
 * 语义与后端保持一致：6 段（秒 分 时 日 月 周），支持 * / 单值 / 列表 / 区间 / 步长，
 * 日与周同时限定按 AND 处理。
 */

/** 秒 分 时 日 月 周 的取值范围 */
const RANGES: Array<[number, number]> = [
  [0, 59],
  [0, 59],
  [0, 23],
  [1, 31],
  [1, 12],
  [0, 6],
]

function rangeOf(item: string, min: number, max: number): [number, number] {
  if (item === '*') {
    return [min, max]
  }
  if (item.includes('-')) {
    const [a, b] = item.split('-', 2)
    return [Number(a), Number(b)]
  }
  return [Number(item), Number(item)]
}

function matchField(field: string, value: number, min: number, max: number): boolean {
  for (const part of field.split(',')) {
    const item = part.trim()
    if (item === '') {
      continue
    }
    if (item === '*') {
      return true
    }
    if (item.includes('/')) {
      const [range, stepText] = item.split('/', 2)
      const step = Number(stepText)
      if (!(step > 0)) {
        continue
      }
      const [start, end] = rangeOf(range, min, max)
      if (value >= start && value <= end && (value - start) % step === 0) {
        return true
      }
      continue
    }
    if (item.includes('-')) {
      const [start, end] = rangeOf(item, min, max)
      if (value >= start && value <= end) {
        return true
      }
      continue
    }
    if (Number(item) === value) {
      return true
    }
  }
  return false
}

/** 从 fromSec 之后找下一次执行时间（秒），一年内找不到返回 null */
function nextRunAt(fields: string[], fromSec: number): number | null {
  const [second, minute, hour, day, month, week] = fields

  let time = fromSec + 1
  const limit = fromSec + 366 * 86400

  while (time <= limit) {
    const d = new Date(time * 1000)
    if (!matchField(month, d.getMonth() + 1, 1, 12)) {
      d.setDate(1)
      d.setMonth(d.getMonth() + 1)
      d.setHours(0, 0, 0, 0)
      time = Math.floor(d.getTime() / 1000)
      continue
    }
    if (!matchField(day, d.getDate(), 1, 31) || !matchField(week, d.getDay(), 0, 6)) {
      d.setHours(0, 0, 0, 0)
      d.setDate(d.getDate() + 1)
      time = Math.floor(d.getTime() / 1000)
      continue
    }
    if (!matchField(hour, d.getHours(), 0, 23)) {
      d.setHours(d.getHours() + 1, 0, 0, 0)
      time = Math.floor(d.getTime() / 1000)
      continue
    }
    if (!matchField(minute, d.getMinutes(), 0, 59)) {
      d.setMinutes(d.getMinutes() + 1, 0, 0)
      time = Math.floor(d.getTime() / 1000)
      continue
    }
    if (!matchField(second, d.getSeconds(), 0, 59)) {
      time++
      continue
    }
    return time
  }

  return null
}

/**
 * 最近 count 次执行时间（秒级时间戳），非法表达式或永不触发时返回空数组。
 *
 * @param expression 6 段 cron 表达式
 * @param from       起始时间（毫秒），默认当前
 */
export function nextRunTimes(expression: string, count: number, from: number = Date.now()): number[] {
  const fields = expression.trim().split(/\s+/).filter(Boolean)
  if (fields.length !== 6) {
    return []
  }

  const out: number[] = []
  let cursor = Math.floor(from / 1000)
  for (let i = 0; i < count; i++) {
    const next = nextRunAt(fields, cursor)
    if (next === null) {
      break
    }
    out.push(next)
    cursor = next
  }

  return out
}

/** 秒级时间戳 → `YYYY-MM-DD HH:mm:ss` */
export function formatCronTime(ts: number): string {
  const d = new Date(ts * 1000)
  const p = (n: number): string => String(n).padStart(2, '0')

  return (
    `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ` +
    `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
  )
}

/** 各段取值范围（编辑器生成选项用） */
export const CRON_RANGES = RANGES

/* ---- 宝塔式「执行周期」与六段表达式的互转 ---- */

export type CronCycle =
  'every_second' | 'every_n_seconds' | 'every_n_minutes' | 'every_n_hours' | 'daily' | 'weekly' | 'monthly' | 'custom'

export interface CronCycleState {
  cycle: CronCycle
  /** 「每 N 秒 / 每 N 分钟 / 每 N 小时」的 N */
  n: number
  /** 时 / 分 / 秒（每天 / 每周 / 每月共用） */
  hour: number
  minute: number
  second: number
  /** 每月几号 */
  day: number
  /** 周几（0=周日） */
  week: number
}

/** 周期 + 时间 → 六段表达式（custom 不在这里处理，由编辑器直通） */
export function buildFromCycle(s: CronCycleState): string {
  switch (s.cycle) {
    case 'every_second':
      return '* * * * * *'
    case 'every_n_seconds':
      return `*/${s.n} * * * * *`
    case 'every_n_minutes':
      return `0 */${s.n} * * * *`
    case 'every_n_hours':
      return `0 0 */${s.n} * * *`
    case 'daily':
      return `${s.second} ${s.minute} ${s.hour} * * *`
    case 'weekly':
      return `${s.second} ${s.minute} ${s.hour} * * ${s.week}`
    case 'monthly':
      return `${s.second} ${s.minute} ${s.hour} ${s.day} * *`
    case 'custom':
      return ''
  }
}

/**
 * 六段表达式 → 周期（能识别成简单周期才切，其余落到「自定义」）。
 *
 * 只识别 buildFromCycle 能写出来的那几类，所以识别回来再生成一定是往返一致的。
 */
export function detectCycle(value: string): CronCycleState {
  const base: CronCycleState = { cycle: 'custom', n: 10, hour: 2, minute: 0, second: 0, day: 1, week: 1 }

  const fields = value.trim().split(/\s+/).filter(Boolean)
  if (fields.length !== 6) {
    return base
  }

  const [s, m, h, d, mo, w] = fields
  const allStar = (list: string[]): boolean => list.every((f) => f === '*')
  const isNum = (v: string): boolean => /^\d+$/.test(v)

  if (allStar(fields)) {
    return { ...base, cycle: 'every_second' }
  }
  if (/^\*\/\d+$/.test(s) && allStar([m, h, d, mo, w])) {
    return { ...base, cycle: 'every_n_seconds', n: Number(s.slice(2)) }
  }
  if (s === '0' && /^\*\/\d+$/.test(m) && allStar([h, d, mo, w])) {
    return { ...base, cycle: 'every_n_minutes', n: Number(m.slice(2)) }
  }
  if (s === '0' && m === '0' && /^\*\/\d+$/.test(h) && allStar([d, mo, w])) {
    return { ...base, cycle: 'every_n_hours', n: Number(h.slice(2)) }
  }

  if (isNum(s) && isNum(m) && isNum(h)) {
    const clock = { second: Number(s), minute: Number(m), hour: Number(h) }
    if (d === '*' && mo === '*' && w === '*') {
      return { ...base, cycle: 'daily', ...clock }
    }
    if (d === '*' && mo === '*' && isNum(w)) {
      return { ...base, cycle: 'weekly', week: Number(w), ...clock }
    }
    if (mo === '*' && w === '*' && isNum(d)) {
      return { ...base, cycle: 'monthly', day: Number(d), ...clock }
    }
  }

  return base
}

/* ---- 表达式 → 「人话」说明（CronEditor 与任务列表共用；文案由调用方按 key 翻译）---- */

/**
 * 说明结构。只覆盖常见形态，认不出来的一律落到 `custom`
 * （列表回退成「自定义表达式」，编辑器本身就是自定义模式）。
 */
export type CronDescription =
  | { key: 'every_second' }
  | { key: 'every_n_seconds'; n: number }
  | { key: 'every_n_minutes'; n: number }
  | { key: 'every_n_hours'; n: number }
  | { key: 'hourly'; minute: number }
  | { key: 'daily'; time: string }
  | { key: 'weekly'; week: number; time: string }
  | { key: 'workday'; time: string }
  | { key: 'monthly'; day: number; time: string }
  | { key: 'custom' }

/** `HH:mm`（秒不为 0 时带上秒，如 `02:30:05`） */
export function formatCronClock(hour: number, minute: number, second: number): string {
  const p = (n: number): string => String(n).padStart(2, '0')

  return second === 0 ? `${p(hour)}:${p(minute)}` : `${p(hour)}:${p(minute)}:${p(second)}`
}

/** 六段表达式 → 说明结构；段数不对或写法认不出来都返回 `custom` */
export function describeCron(expression: string): CronDescription {
  const fields = expression.trim().split(/\s+/).filter(Boolean)
  if (fields.length !== 6) {
    return { key: 'custom' }
  }

  const [second, minute, hour, day, month, week] = fields
  const isStar = (v: string): boolean => v === '*'
  const isNum = (v: string): boolean => /^\d+$/.test(v)

  if (fields.every(isStar)) {
    return { key: 'every_second' }
  }

  // 每 N 秒 / 分 / 时：编辑器生成的就是这几种，直接复用识别结果
  const cycle = detectCycle(expression)
  switch (cycle.cycle) {
    case 'every_n_seconds':
      return { key: 'every_n_seconds', n: cycle.n }
    case 'every_n_minutes':
      return { key: 'every_n_minutes', n: cycle.n }
    case 'every_n_hours':
      return { key: 'every_n_hours', n: cycle.n }
    default:
      break
  }

  // 整分触发 + 固定分钟 → 每小时的第 m 分（秒不为 0 一律当自定义，避免说得不准）
  if (second === '0' && isNum(minute) && isStar(hour) && isStar(day) && isStar(month) && isStar(week)) {
    return { key: 'hourly', minute: Number(minute) }
  }

  if (!isNum(second) || !isNum(minute) || !isNum(hour)) {
    return { key: 'custom' }
  }

  const time = formatCronClock(Number(hour), Number(minute), Number(second))

  if (isStar(day) && isStar(month)) {
    if (isStar(week)) {
      return { key: 'daily', time }
    }
    if (isNum(week)) {
      return { key: 'weekly', week: Number(week) % 7, time }
    }
    if (week === '1-5') {
      return { key: 'workday', time }
    }

    return { key: 'custom' }
  }

  if (isStar(week) && isStar(month) && isNum(day)) {
    return { key: 'monthly', day: Number(day), time }
  }

  return { key: 'custom' }
}
