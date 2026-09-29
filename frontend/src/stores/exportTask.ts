import { defineStore } from 'pinia'
import { ref } from 'vue'
import { exportTaskList, type ExportTaskRow } from '@/api/export'

/** 待处理 / 处理中（未完成）的任务状态 */
const UNFINISHED = [0, 1] as const

/** 未完成任务变化时轮询列表的间隔（毫秒） */
const POLL_INTERVAL = 15000

/** 消息中心抽屉的 tab：我的消息 / 导出任务 */
export type MessageCenterTab = 'notice' | 'export'

/**
 * 全局导出任务中心 + 顶栏「消息 / 导出任务」合并抽屉的开合状态。
 *
 * 角标读 `unfinished`，抽屉里的「导出任务」tab 读 `items`；各页面发起导出后
 * 调 `open('export')` 即可打开抽屉并定位到导出任务 tab。
 * 只有存在未完成任务时才轮询（完成后自动停），避免空转查询。
 */
export const useExportTaskStore = defineStore('exportTask', () => {
  const items = ref<ExportTaskRow[]>([])
  const loading = ref(false)
  const unfinished = ref(0)
  const visible = ref(false)
  const activeTab = ref<MessageCenterTab>('notice')

  let timer: number | null = null

  async function load(options: { silent?: boolean } = {}): Promise<void> {
    if (!options.silent) {
      loading.value = true
    }
    try {
      items.value = await exportTaskList()
      unfinished.value = items.value.filter((item) => (UNFINISHED as readonly number[]).includes(item.status)).length
    } catch {
      // 失败静默：角标不是关键路径（拦截器已提示）。轮询失败时停掉，避免反复打失败请求。
      stopPolling()
      if (!options.silent) {
        items.value = []
      }
      unfinished.value = 0
    } finally {
      if (!options.silent) {
        loading.value = false
      }
    }
    syncPolling()
  }

  /** 打开消息中心抽屉并定位到指定 tab（默认导出任务），同时刷新列表 */
  function open(tab: MessageCenterTab = 'export'): void {
    visible.value = true
    activeTab.value = tab
    void load()
  }

  function close(): void {
    visible.value = false
  }

  /** 有未完成任务就轮询，没有就停（避免无谓的定时查询） */
  function syncPolling(): void {
    if (unfinished.value > 0) {
      startPolling()
    } else {
      stopPolling()
    }
  }

  function startPolling(): void {
    if (timer !== null) {
      return
    }
    timer = window.setInterval(() => {
      void load({ silent: true })
    }, POLL_INTERVAL)
  }

  function stopPolling(): void {
    if (timer !== null) {
      window.clearInterval(timer)
      timer = null
    }
  }

  /** 登出 / 换账号时清空，避免残留上一个账号的任务 */
  function reset(): void {
    stopPolling()
    items.value = []
    unfinished.value = 0
    visible.value = false
    activeTab.value = 'notice'
  }

  return { items, loading, unfinished, visible, activeTab, load, open, close, reset }
})
