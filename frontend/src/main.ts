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
import zhCn from 'element-plus/es/locale/lang/zh-cn'

import App from './App.vue'
import router from './router'
import { setupDirectives } from './directives'
import { useAppStore } from './stores/app'
import { useSettingStore } from './stores/setting'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(ElementPlus, { locale: zhCn, zIndex: 3000 })
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
