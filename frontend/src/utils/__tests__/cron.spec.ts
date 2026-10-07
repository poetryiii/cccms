import { describe, expect, it } from 'vitest'
import {
  buildFromCycle,
  CRON_RANGES,
  describeCron,
  detectCycle,
  formatCronClock,
  formatCronTime,
  nextRunTimes,
  type CronCycle,
  type CronCycleState,
} from '@/utils/cron'

/** 便于断言：把**本地**时间构造成「秒级时间戳」（与 `nextRunTimes` 的取值口径一致） */
function at(year: number, month: number, day: number, hour = 0, minute = 0, second = 0): number {
  return Math.floor(new Date(year, month - 1, day, hour, minute, second).getTime() / 1000)
}

describe('nextRunTimes', () => {
  it('每秒钟执行：返回连续 3 个秒级时间戳', () => {
    const from = at(2026, 1, 15, 10, 30, 5) * 1000
    expect(nextRunTimes('* * * * * *', 3, from)).toEqual([from / 1000 + 1, from / 1000 + 2, from / 1000 + 3])
  })

  it('每分钟整点执行：从 10:30:05 出发应命中 10:31:00', () => {
    const from = at(2026, 1, 15, 10, 30, 5) * 1000
    expect(nextRunTimes('0 * * * * *', 1, from)).toEqual([at(2026, 1, 15, 10, 31, 0)])
  })

  it('每天 02:00:00 执行：应跳到次日凌晨两点', () => {
    const from = at(2026, 1, 15, 10, 30, 0) * 1000
    expect(nextRunTimes('0 0 2 * * *', 1, from)).toEqual([at(2026, 1, 16, 2, 0, 0)])
  })

  it('支持列表 / 区间 / 步长三种写法', () => {
    const from = at(2026, 1, 15, 10, 30, 0) * 1000
    // 每分钟的第 0、30 秒
    expect(nextRunTimes('0,30 * * * * *', 2, from)).toEqual([at(2026, 1, 15, 10, 30, 30), at(2026, 1, 15, 10, 31, 0)])
    // 每 20 秒
    expect(nextRunTimes('*/20 * * * * *', 2, from)).toEqual([at(2026, 1, 15, 10, 30, 20), at(2026, 1, 15, 10, 30, 40)])
    // 10~12 点之间每分钟
    const from2 = at(2026, 1, 15, 13, 0, 0) * 1000
    expect(nextRunTimes('0 * 10-12 * * *', 1, from2)).toEqual([at(2026, 1, 16, 10, 0, 0)])
  })

  it('日与周同时限定按 AND 处理（不是 OR）', () => {
    // 2026-01-15 是周四：若按 OR，下一个「周一」在 01-19 就会触发；
    // 按 AND 必须等到「15 号且周一」同时成立，因此结果应远晚于 01-19。
    const from = at(2026, 1, 15, 0, 0, 0) * 1000
    const next = nextRunTimes('0 0 0 15 * 1', 1, from)

    expect(next).toHaveLength(1)
    const d = new Date(next[0] * 1000)
    expect(d.getDate()).toBe(15)
    expect(d.getDay()).toBe(1)
    expect(next[0]).toBeGreaterThan(at(2026, 1, 20, 0, 0, 0))
  })

  it('段数不足 6 段视为非法表达式，返回空数组', () => {
    expect(nextRunTimes('* * * * *', 1)).toEqual([])
    expect(nextRunTimes('', 1)).toEqual([])
    expect(nextRunTimes('0 0 2 * * * extra', 1)).toEqual([])
  })

  it('永不触发（2 月 31 日）时一年内找不到，返回空数组', () => {
    const from = at(2026, 1, 1, 0, 0, 0) * 1000
    expect(nextRunTimes('0 0 0 31 2 *', 1, from)).toEqual([])
  })
})

describe('formatCronTime', () => {
  it('按本地时间格式化为 YYYY-MM-DD HH:mm:ss 并补零', () => {
    expect(formatCronTime(at(2026, 1, 5, 3, 4, 5))).toBe('2026-01-05 03:04:05')
  })
})

describe('buildFromCycle / detectCycle', () => {
  const base: CronCycleState = { cycle: 'custom', n: 10, hour: 2, minute: 30, second: 5, day: 1, week: 1 }

  it('各周期生成正确的六段表达式', () => {
    expect(buildFromCycle({ ...base, cycle: 'every_second' })).toBe('* * * * * *')
    expect(buildFromCycle({ ...base, cycle: 'every_n_seconds' })).toBe('*/10 * * * * *')
    expect(buildFromCycle({ ...base, cycle: 'every_n_minutes' })).toBe('0 */10 * * * *')
    expect(buildFromCycle({ ...base, cycle: 'every_n_hours' })).toBe('0 0 */10 * * *')
    expect(buildFromCycle({ ...base, cycle: 'daily' })).toBe('5 30 2 * * *')
    expect(buildFromCycle({ ...base, cycle: 'weekly' })).toBe('5 30 2 * * 1')
    expect(buildFromCycle({ ...base, cycle: 'monthly' })).toBe('5 30 2 1 * *')
    // custom 由编辑器直通，不生成表达式
    expect(buildFromCycle({ ...base, cycle: 'custom' })).toBe('')
  })

  it('生成 → 识别 → 再生成，往返一致（custom 除外）', () => {
    const cycles: CronCycle[] = [
      'every_second',
      'every_n_seconds',
      'every_n_minutes',
      'every_n_hours',
      'daily',
      'weekly',
      'monthly',
    ]
    for (const cycle of cycles) {
      const expression = buildFromCycle({ ...base, cycle })
      const detected = detectCycle(expression)
      expect(detected.cycle, `${cycle} 应被识别（表达式 ${expression}）`).toBe(cycle)
      expect(buildFromCycle(detected), `${cycle} 往返后表达式应一致`).toBe(expression)
    }
  })

  it('识别不了的写法落到自定义', () => {
    expect(detectCycle('1 2 3 4 5 6').cycle).toBe('custom')
    expect(detectCycle('* * * * *').cycle).toBe('custom')
    expect(detectCycle('0 0 2 5 * 1').cycle).toBe('custom') // 日与周同时限定
    expect(detectCycle('0,30 * * * * *').cycle).toBe('custom')
  })

  it('日与周同时为 * 时优先识别为每天，而不是每月', () => {
    expect(detectCycle('5 30 2 * * *').cycle).toBe('daily')
  })
})

describe('CRON_RANGES', () => {
  it('六段取值范围与后端 CronMatcher 一致（秒/分/时/日/月/周）', () => {
    expect(CRON_RANGES).toEqual([
      [0, 59],
      [0, 59],
      [0, 23],
      [1, 31],
      [1, 12],
      [0, 6],
    ])
  })
})

describe('describeCron / formatCronClock', () => {
  it('常见形态都能给出结构化说明', () => {
    expect(describeCron('* * * * * *')).toEqual({ key: 'every_second' })
    expect(describeCron('*/30 * * * * *')).toEqual({ key: 'every_n_seconds', n: 30 })
    expect(describeCron('0 */30 * * * *')).toEqual({ key: 'every_n_minutes', n: 30 })
    expect(describeCron('0 0 */5 * * *')).toEqual({ key: 'every_n_hours', n: 5 })
    expect(describeCron('0 30 * * * *')).toEqual({ key: 'hourly', minute: 30 })
    expect(describeCron('0 0 2 * * *')).toEqual({ key: 'daily', time: '02:00' })
    expect(describeCron('5 30 2 * * 1')).toEqual({ key: 'weekly', week: 1, time: '02:30:05' })
    expect(describeCron('0 0 9 * * 1-5')).toEqual({ key: 'workday', time: '09:00' })
    expect(describeCron('0 0 4 1 * *')).toEqual({ key: 'monthly', day: 1, time: '04:00' })
  })

  it('认不出来的写法落到 custom（段数不对也算）', () => {
    expect(describeCron('')).toEqual({ key: 'custom' })
    expect(describeCron('0 0 2 * * * extra')).toEqual({ key: 'custom' })
    expect(describeCron('1 2 3 4 5 6')).toEqual({ key: 'custom' })
    // 日与周同时限定、列表写法都不做推断
    expect(describeCron('0 0 0 15 * 1')).toEqual({ key: 'custom' })
    expect(describeCron('0,30 * * * * *')).toEqual({ key: 'custom' })
    // 秒不为 0 的「每小时」也当自定义，避免说得不准
    expect(describeCron('30 30 * * * *')).toEqual({ key: 'custom' })
  })

  it('formatCronClock 秒为 0 时省略秒', () => {
    expect(formatCronClock(2, 0, 0)).toBe('02:00')
    expect(formatCronClock(2, 30, 5)).toBe('02:30:05')
  })
})
