import { ref, type Ref } from 'vue'

/**
 * ArtTable 的**全局**尺寸偏好：表格本体与工具栏控件各一份。
 *
 * 为什么是两份而不是一份：这两处的诉求常常相反 —— 典型组合是「表格压到紧凑塞下更多行，
 * 但工具栏主操作按钮保持默认大小」（全变小号会让主操作不显眼）。绑成一份就没法这么配。
 *
 * 与列显隐 / 列宽那两项刻意不同：它们按页面分别记忆（`cccms_table_hidden:` + 路由），
 * 而尺寸**不带页面标识** —— 「表格太挤 / 按钮太小」是跨页面的整体感受，
 * 逐页设置等于让用户在每个列表里重复调一遍，所以这里是全局一份。
 *
 * 取值 `''` 表示未设置：此时由 ArtTable 的对应 prop（页面声明）或 Element Plus 的
 * 全局尺寸配置决定，用户点「跟随默认」就是回到这个状态。
 */

/** 可选的尺寸（与 el-table / el-config-provider 的 size 取值一致） */
export type TableSize = 'large' | 'default' | 'small'

const VALID: readonly string[] = ['large', 'default', 'small']

/** 前缀与其它表格偏好保持一致（见 ArtTable 的 `cccms_table_hidden:` / `cccms_table_width:`） */
const SIZE_KEY = 'cccms_table_size:'
const BUTTON_SIZE_KEY = 'cccms_table_button_size:'

function read(storageKey: string): TableSize | '' {
  try {
    const raw = localStorage.getItem(storageKey)

    return raw !== null && VALID.includes(raw) ? (raw as TableSize) : ''
  } catch {
    // 隐私模式 / storage 被禁用：当作未设置，不影响页面可用性
    return ''
  }
}

/** 造一个「响应式偏好 + 持久化写入」的组合，两份偏好共用同一套读写与容错逻辑 */
function createSizePreference(storageKey: string): [Ref<TableSize | ''>, (value: TableSize | '') => void] {
  /*
   * 模块级 ref 单例：同一页面里若有多个 ArtTable，改一次会同步生效。
   * 不用 Pinia —— 一个 UI 偏好没必要引入 store；模块级 ref 已具备同样的共享语义，
   * 且删掉这个文件就彻底移除该功能，没有隐藏的全局状态。
   */
  const preference = ref<TableSize | ''>(read(storageKey))

  /** 写入偏好并持久化；传 `''` 表示清除偏好、回到「跟随默认」 */
  const set = (value: TableSize | ''): void => {
    preference.value = value
    try {
      if (value === '') {
        localStorage.removeItem(storageKey)
      } else {
        localStorage.setItem(storageKey, value)
      }
    } catch {
      // 写不进去（隐私模式）不影响本次会话内的效果，下次刷新会回到默认
    }
  }

  return [preference, set]
}

/** 表格本体的尺寸偏好 */
export const [tableSizePreference, setTableSizePreference] = createSizePreference(SIZE_KEY)

/** 工具栏 / 搜索区控件的尺寸偏好（含页面通过插槽传进来的按钮） */
export const [buttonSizePreference, setButtonSizePreference] = createSizePreference(BUTTON_SIZE_KEY)
