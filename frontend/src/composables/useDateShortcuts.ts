import { computed, type ComputedRef } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  lastMonthRange,
  lastWeekRange,
  recentDaysRange,
  thisMonthRange,
  todayRange,
  yesterdayRange,
  type DateRange,
} from '@/utils/dateRange'

export interface DateShortcut {
  text: string
  value: () => DateRange
}

/**
 * 日期范围选择器的快捷项：今天 / 昨天 / 近 3·7·15·30 天 / 上周 / 本月 / 上月。
 *
 * 用法（`el-date-picker` 的 `shortcuts` 直接吃这个数组）：
 *
 *   const shortcuts = useDateShortcuts()
 *   <el-date-picker v-model="range" type="daterange" value-format="YYYY-MM-DD" :shortcuts="shortcuts" />
 *
 * 快捷项返回的是 `[Date, Date]`，`value-format` 由选择器自己序列化（Element Plus 在
 * `emitInput` 里按 `value-format` 转字符串），所以 `daterange` / `datetimerange` 都能直接用；
 * 点快捷项同样会走 `change`，页面原有的「改动即查询」不用额外处理。
 *
 * 为什么集中在 composable 里：
 *  - 文案走 i18n（`core.table.time*`），一处改全站生效；
 *  - 返回 `computed`：切换语言后文案要跟着变，写死成数组就变不了；
 *  - `value()` 里才 `new Date()`，语义是「点下去那一刻」的今天 / 上周（见 `utils/dateRange`）。
 */
export function useDateShortcuts(): ComputedRef<DateShortcut[]> {
  const { t } = useI18n({ useScope: 'global' })

  return computed<DateShortcut[]>(() => [
    { text: t('core.table.timeToday'), value: () => todayRange(new Date()) },
    { text: t('core.table.timeYesterday'), value: () => yesterdayRange(new Date()) },
    { text: t('core.table.timeLast3Days'), value: () => recentDaysRange(new Date(), 3) },
    { text: t('core.table.timeLast7Days'), value: () => recentDaysRange(new Date(), 7) },
    { text: t('core.table.timeLast15Days'), value: () => recentDaysRange(new Date(), 15) },
    { text: t('core.table.timeLast30Days'), value: () => recentDaysRange(new Date(), 30) },
    { text: t('core.table.timeLastWeek'), value: () => lastWeekRange(new Date()) },
    { text: t('core.table.timeThisMonth'), value: () => thisMonthRange(new Date()) },
    { text: t('core.table.timeLastMonth'), value: () => lastMonthRange(new Date()) },
  ])
}
