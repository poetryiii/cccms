import { describe, expect, it } from 'vitest'
import {
  lastMonthRange,
  lastWeekRange,
  recentDaysRange,
  shiftDays,
  thisMonthRange,
  todayRange,
  yesterdayRange,
} from '@/utils/dateRange'

/** 断言用的本地时间文本：与筛选控件写入 query 的 `YYYY-MM-DD HH:mm:ss` 一致 */
function fmt(date: Date): string {
  const pad = (value: number): string => String(value).padStart(2, '0')

  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
  )
}

/** 构造本地时间：默认下午 15:30，确保「今天」不会因为处在 00:00 而掩盖边界问题 */
const at = (year: number, month: number, day: number, hour = 15, minute = 30): Date =>
  new Date(year, month - 1, day, hour, minute, 0)

describe('日期范围快捷项', () => {
  it('今天 / 昨天：两端补到 00:00:00 ~ 23:59:59（不是「此刻」）', () => {
    expect(todayRange(at(2026, 10, 26)).map(fmt)).toEqual(['2026-10-26 00:00:00', '2026-10-26 23:59:59'])
    expect(yesterdayRange(at(2026, 10, 26)).map(fmt)).toEqual(['2026-10-25 00:00:00', '2026-10-25 23:59:59'])
  })

  it('昨天跨月 / 跨年：按日历日回退，不按 24 小时', () => {
    expect(yesterdayRange(at(2026, 3, 1)).map(fmt)).toEqual(['2026-02-28 00:00:00', '2026-02-28 23:59:59'])
    expect(yesterdayRange(at(2026, 1, 1)).map(fmt)).toEqual(['2025-12-31 00:00:00', '2025-12-31 23:59:59'])
  })

  it('最近 N 天含今天：15 天 = 14 天前 00:00 ~ 今天 23:59', () => {
    expect(recentDaysRange(at(2026, 10, 26), 15).map(fmt)).toEqual([
      '2026-10-12 00:00:00',
      '2026-10-26 23:59:59',
    ])
    // N=1 等价于今天（防御 0 / 负数）
    expect(recentDaysRange(at(2026, 10, 26), 0).map(fmt)).toEqual(['2026-10-26 00:00:00', '2026-10-26 23:59:59'])
  })

  it('上周：周一起算到上周日 23:59:59', () => {
    // 2026-10-26 是周一 → 上周 10-19 ~ 10-25
    expect(lastWeekRange(at(2026, 10, 26)).map(fmt)).toEqual(['2026-10-19 00:00:00', '2026-10-25 23:59:59'])
    // 2026-10-25 是周日，仍算「本周」→ 上周 10-12 ~ 10-18
    expect(lastWeekRange(at(2026, 10, 25)).map(fmt)).toEqual(['2026-10-12 00:00:00', '2026-10-18 23:59:59'])
  })

  it('本月到今天；上月整天（跨月 / 跨年 / 闰年外的 2 月）', () => {
    expect(thisMonthRange(at(2026, 10, 26)).map(fmt)).toEqual(['2026-10-01 00:00:00', '2026-10-26 23:59:59'])
    expect(lastMonthRange(at(2026, 10, 26)).map(fmt)).toEqual(['2026-09-01 00:00:00', '2026-09-30 23:59:59'])
    expect(lastMonthRange(at(2026, 3, 31)).map(fmt)).toEqual(['2026-02-01 00:00:00', '2026-02-28 23:59:59'])
    expect(lastMonthRange(at(2026, 1, 5)).map(fmt)).toEqual(['2025-12-01 00:00:00', '2025-12-31 23:59:59'])
  })

  it('shiftDays 不改动传入的 Date（快捷项之间互不影响）', () => {
    const base = at(2026, 10, 31)
    const shifted = shiftDays(base, 1)

    expect(fmt(base)).toBe('2026-10-31 15:30:00')
    expect(fmt(shifted)).toBe('2026-11-01 15:30:00')
  })
})
