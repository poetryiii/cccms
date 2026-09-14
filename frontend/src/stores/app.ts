import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { configUi, type AppUiConfig } from '@/api/config'
import { useSettingStore } from './setting'

/** 取不到后台配置时的兜底值（保证界面永远能正常渲染） */
const FALLBACK: AppUiConfig = {
  system: { name: 'CCCMS', logo: '', icp: '', copyright: '', maintenance: false, notice: '' },
  ui: { theme_mode: 'light', theme_primary: '#2b6cff', page_size: 15, tags_view: true, container_width: 0 },
}

/**
 * 内置 Logo：`frontend/public/logo.png`，构建时随 dist 拷到站点根目录。
 *
 * 后台 `system.logo` 留空时用它 —— 所以默认就有 Logo，不必先去配置管理里填 URL。
 */
const DEFAULT_LOGO = '/logo.png'

/**
 * 品牌与 UI 默认值（来自 sys_config 的 system.* / ui.* 分组）。
 *
 * 启动时拉一次，用于：系统名称、Logo、备案号、版权、维护公告、
 * 默认主题（新用户）、默认分页条数、多标签页开关。
 */
export const useAppStore = defineStore('app', () => {
  const config = ref<AppUiConfig>({
    system: { ...FALLBACK.system },
    ui: { ...FALLBACK.ui },
  })
  const loaded = ref(false)

  const systemName = computed(() => config.value.system.name || FALLBACK.system.name)
  /** 品牌 Logo：后台 `system.logo` 优先，留空回退内置 Logo（侧边栏 / 登录页共用） */
  const logo = computed(() => config.value.system.logo || DEFAULT_LOGO)
  const icp = computed(() => config.value.system.icp)
  const copyright = computed(() => config.value.system.copyright)
  const maintenance = computed(() => config.value.system.maintenance === true)
  const notice = computed(() => config.value.system.notice || '系统维护中，请稍后访问')

  const pageSize = computed(() => Number(config.value.ui.page_size) || FALLBACK.ui.page_size)
  const tagsView = computed(() => config.value.ui.tags_view !== false)

  async function load(): Promise<void> {
    try {
      const res = await configUi()
      if (res) {
        config.value = {
          system: { ...FALLBACK.system, ...(res.system ?? {}) },
          ui: { ...FALLBACK.ui, ...(res.ui ?? {}) },
        }
      }
    } catch {
      // 拿不到后台配置就用兜底值，绝不阻塞启动
    } finally {
      loaded.value = true
      // 首次访问（本机无偏好）时，把后台下发的主题作为默认值
      useSettingStore().applySystemDefaults(config.value.ui)
    }
  }

  return {
    config,
    loaded,
    systemName,
    logo,
    icp,
    copyright,
    maintenance,
    notice,
    pageSize,
    tagsView,
    load,
  }
})
