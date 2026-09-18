// 样式顺序有讲究：Tailwind(含 preflight) → Element Plus → 覆盖层 → 业务全局
import '@/assets/styles/index.css'
import 'element-plus/dist/index.css'
import 'element-plus/theme-chalk/dark/css-vars.css'
import '@/assets/styles/element.css'
import '@/assets/styles/app.css'
import 'nprogress/nprogress.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'

import App from './App.vue'
import router from './router'
import { i18n } from './locales'
import { setupDirectives } from './directives'
import { useAppStore } from './stores/app'
import { useSettingStore } from './stores/setting'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
// Element Plus 的语言包不在这里写死：App.vue 用 ElConfigProvider 按当前语言响应式下发，
// 这样切换语言后日期选择器 / 分页等内置文案会立即跟随，无需刷新页面。
app.use(ElementPlus, { zIndex: 3000 })
app.use(i18n)
setupDirectives(app)
app.use(router)

// 先用本地/内置默认值应用主题，避免白屏闪烁
useSettingStore(pinia).init()

/**
 * 再拉后台下发的品牌与 UI 默认值（系统名称/Logo/默认主题/分页/多标签/维护公告）。
 * 最多等 2.5 秒：拿不到就用兜底值，绝不因为一个配置请求把应用卡住。
 */
const appStore = useAppStore(pinia)
const ready = Promise.race([
  appStore.load(),
  new Promise<void>((resolve) => {
    window.setTimeout(resolve, 2500)
  }),
])

void ready.finally(() => {
  app.mount('#app')

  // 移除首屏 loading（index.html 内联）
  const loader = document.getElementById('cccms-loader')
  if (loader) {
    loader.classList.add('is-hide')
    window.setTimeout(() => loader.remove(), 300)
  }
})
