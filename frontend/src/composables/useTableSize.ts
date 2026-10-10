import { ref } from 'vue'

/**
 * ArtTable 的**全局密度**偏好（紧凑 / 默认 / 宽松），一份设置同时决定：
 *   - 表格本体尺寸（行高 / 字号 / 内边距）
 *   - 工具栏与搜索区控件尺寸（含页面通过插槽传进来的按钮）
 *
 * 为什么合并成一份：早期版本把两者拆成独立偏好，但对用户来说「这一屏是挤还是松」
 * 本来就是一个整体感受 —— 两个入口、两个开关，反而要先想「我该调哪个」。
 * 合并后档位也少了一半（3×3 种组合 → 3 档）。
 *
 * 与列显隐 / 列宽那两项刻意不同：它们按页面分别记忆（`cccms_table_hidden:` + 路由），
 * 而密度**不带页面标识** —— 「表格太挤」是跨页面的整体感受，
 * 逐页设置等于让用户在每个列表里重复调一遍，所以这里是全局一份。
 *
 * 取值 `''` 表示未设置：此时由 ArtTable 的 `size` / `buttonSize` prop
 * （页面声明）或 Element Plus 的全局尺寸配置决定。
 */

/** 密度档位（与 el-table / el-config-provider 的 size 取值一致） */
export type TableSize = 'large' | 'default' | 'small'

const VALID: readonly string[] = ['large', 'default', 'small']

/** 密度偏好的存储键（前缀与列显隐 / 列宽保持一致） */
const DENSITY_KEY = 'cccms_table_density:'

/**
 * 早期版本把「表格大小」与「按钮大小」拆成两份记录，本版本合并成一个概念。
 * 这里只用于**读取时的一次性迁移**，之后不再写入。
 */
const LEGACY_KEYS = ['cccms_table_size:', 'cccms_table_button_size:']

function safeGet(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    // 隐私模式 / storage 被禁用
    return null
  }
}

function safeSet(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    // 写不进去不影响本次会话内的效果，下次刷新会回到默认
  }
}

function safeRemove(key: string): void {
  try {
    localStorage.removeItem(key)
  } catch {
    // 忽略
  }
}

/** 把存储原文收敛成合法档位；非法 / 缺失一律当作「未设置」 */
function normalize(raw: string | null): TableSize | '' {
  return raw !== null && VALID.includes(raw) ? (raw as TableSize) : ''
}

function readDensity(): TableSize | '' {
  const current = normalize(safeGet(DENSITY_KEY))
  if (current !== '') {
    return current
  }

  /*
   * 迁移旧的两份偏好：任一份有值就当作密度，并顺手写到新键、清掉旧键。
   * 不清掉的话旧值会长期残留，将来若有人再读它们就会和新设置互相矛盾。
   */
  for (const key of LEGACY_KEYS) {
    const legacy = normalize(safeGet(key))
    if (legacy !== '') {
      safeRemove(key)
      safeSet(DENSITY_KEY, legacy)

      return legacy
    }
  }

  return ''
}

/**
 * 全局密度偏好。
 *
 * 模块级 ref 单例：同一页面里若有多个 ArtTable，改一次会同步生效。
 * 不用 Pinia —— 一个 UI 偏好没必要引入 store；模块级 ref 已具备同样的共享语义，
 * 且删掉这个文件就彻底移除该功能，没有隐藏的全局状态。
 */
export const densityPreference = ref<TableSize | ''>(readDensity())

/** 写入密度偏好并持久化；传 `''` 表示清除偏好、回到「跟随页面 / 全局配置」 */
export function setDensityPreference(value: TableSize | ''): void {
  densityPreference.value = value

  if (value === '') {
    safeRemove(DENSITY_KEY)

    return
  }

  safeSet(DENSITY_KEY, value)
}
