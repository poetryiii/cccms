import { defineStore } from 'pinia'
import { ref } from 'vue'
import { exportTaskList, type ExportTaskRow } from '@/api/export'

/** 待处理 / 处理中（未完成）的任务状态 */
const UNFINISHED = [0, 1] as const

/** 未完成任务变化时轮询列表的间隔（毫秒） */
const POLL_INTERVAL = 15000

/**
 * 全局导出任务中心。
 *
 * 顶栏常驻「导出任务」图标 + 未完成角标，各页面发起导出后统一把任务投递到这里；
 * 只有存在未完成任务时才轮询（完成后自动停），避免空转查询。
 */
export const useExportTaskStore = defineStore('exportTask', () => {
  const items = ref<ExportTaskRow[]>([])
  const visible = ref(false)
  const loading = ref(false)
  const unfinished = ref(0)

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

  /** 打开抽屉并刷新列表 */
  function open(): void {
    visible.value = true
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
  }

  return { items, visible, loading, unfinished, load, open, close, reset }
})
