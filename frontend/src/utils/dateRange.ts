/**
 * 日期范围快捷项的时间计算（纯函数，便于单测）。
 *
 * ## 为什么两端都落在天边界
 *
 * 筛选控件是 `datetimerange`（带时分秒），后端按字符串直接比较。若快捷项把起点算成
 * 「此刻」，同一个「今天」在上午点就会漏掉当天 00:00 之后的记录 —— 范围必须补成
 * `00:00:00 ~ 23:59:59`。
 *
 * ## 为什么 `now` 由调用方传入
 *
 * 快捷项的语义是「**点的时候**相对此刻」，不是「组件挂载时」。把 `new Date()` 交给
 * 调用方在 `value()` 里取，页面挂到第二天也不会把「今天」算成昨天；同时纯函数的
 * 结果可复现，单测不必伪造时钟。
 */

export type DateRange = [Date, Date]

/** 当天 00:00:00 */
export function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0)
}

/** 当天 23:59:59 */
export function endOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59)
}

/** 按**日历日**平移 N 天：自动跨月 / 跨年，不按 24 小时算（避免夏令时下差一小时） */
export function shiftDays(date: Date, days: number): Date {
  const next = new Date(date)
  next.setDate(next.getDate() + days)

  return next
}

/** 今天 */
export function todayRange(now: Date): DateRange {
  return [startOfDay(now), endOfDay(now)]
}

/** 昨天 */
export function yesterdayRange(now: Date): DateRange {
  const day = shiftDays(now, -1)

  return [startOfDay(day), endOfDay(day)]
}

/** 最近 N 天（**含今天**）：N=3 即前天 00:00 ~ 今天 23:59 */
export function recentDaysRange(now: Date, days: number): DateRange {
  const span = Number.isFinite(days) ? Math.max(1, Math.floor(days)) : 1

  return [startOfDay(shiftDays(now, -(span - 1))), endOfDay(now)]
}

/** 上周：上一个自然周的周一 00:00 ~ 周日 23:59（按国内习惯周一起算） */
export function lastWeekRange(now: Date): DateRange {
  // getDay()：周日 0 … 周六 6，换算成「距本周一几天」
  const offsetFromMonday = (now.getDay() + 6) % 7
  const thisMonday = shiftDays(startOfDay(now), -offsetFromMonday)

  // 上周一 ~ 本周一的前一天（周日）
  return [shiftDays(thisMonday, -7), endOfDay(shiftDays(thisMonday, -1))]
}

/** 本月：1 号 00:00 ~ 今天 23:59（不取月末 —— 未来日期没有数据，白白拉长范围） */
export function thisMonthRange(now: Date): DateRange {
  return [new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0), endOfDay(now)]
}

/** 上月整天：上月 1 号 00:00 ~ 上月最后一天 23:59 */
export function lastMonthRange(now: Date): DateRange {
  return [
    new Date(now.getFullYear(), now.getMonth() - 1, 1, 0, 0, 0),
    // 本月第 0 天 = 上月最后一天（不用记 28/29/30/31）
    new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59),
  ]
}
