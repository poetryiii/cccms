import { ref } from 'vue'

/**
 * ArtTable 的**全局**表格尺寸偏好。
 *
 * 与列显隐 / 列宽那两项刻意不同：它们按页面分别记忆（`cccms_table_hidden:` + 路由），
 * 而尺寸**不带页面标识** —— 「表格太挤 / 太空」是跨页面的整体感受，
 * 逐页设置等于让用户在每个列表里重复调一遍，所以这里是全局一份。
 *
 * 取值 `''` 表示未设置：此时由 ArtTable 的 `size` prop（页面声明）或 Element Plus 的
 * 全局尺寸配置决定，用户点「跟随默认」就是回到这个状态。
 */

/** 可选的表格尺寸（与 el-table 的 size 取值一致） */
export type TableSize = 'large' | 'default' | 'small'

/** 全局存储键。**不带页面标识**是有意的，见文件头说明；前缀与其它表格偏好保持一致。 */
const STORAGE_KEY = 'cccms_table_size:'

const VALID: readonly string[] = ['large', 'default', 'small']

function read(): TableSize | '' {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)

    return raw !== null && VALID.includes(raw) ? (raw as TableSize) : ''
  } catch {
    // 隐私模式 / storage 被禁用：当作未设置，不影响页面可用性
    return ''
  }
}

/**
 * 模块级单例：同一页面里若有多个 ArtTable，改一次会同步生效。
 *
 * 不用 Pinia —— 一个 UI 偏好没必要引入 store；模块级 ref 已具备同样的共享语义，
 * 且删掉这个文件就彻底移除该功能，没有隐藏的全局状态。
 */
export const tableSizePreference = ref<TableSize | ''>(read())

/** 写入偏好并持久化；传 `''` 表示清除偏好、回到「跟随默认」 */
export function setTableSizePreference(value: TableSize | ''): void {
  tableSizePreference.value = value
  try {
    if (value === '') {
      localStorage.removeItem(STORAGE_KEY)
    } else {
      localStorage.setItem(STORAGE_KEY, value)
    }
  } catch {
    // 写不进去（隐私模式）不影响本次会话内的效果，下次刷新会回到默认
  }
}
