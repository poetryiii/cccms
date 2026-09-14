<template>
  <div class="layout">
    <LayoutSidebar :collapsed="collapsed" :mobile="isMobile" />
    <div v-if="isMobile && !collapsed" class="layout-mask" @click="collapsed = true" />
    <div class="layout-main">
      <LayoutHeader v-model:collapsed="collapsed" />
      <!-- 多标签页开关来自后台配置 ui.tags_view -->
      <LayoutTagbar v-if="appStore.tagsView" />
      <LayoutContent />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useMediaQuery } from '@vueuse/core'
import LayoutSidebar from './components/LayoutSidebar.vue'
import LayoutHeader from './components/LayoutHeader.vue'
import LayoutTagbar from './components/LayoutTagbar.vue'
import LayoutContent from './components/LayoutContent.vue'
import { useAppStore } from '@/stores/app'
import { useWorktabStore } from '@/stores/worktab'

const route = useRoute()
const worktab = useWorktabStore()
const appStore = useAppStore()

const isMobile = useMediaQuery('(max-width: 900px)')
const collapsed = ref(false)

/** 移动端默认收起为抽屉，切回桌面端自动展开 */
watch(
  isMobile,
  (value) => {
    collapsed.value = value
  },
  { immediate: true },
)

/** 路由变化 → 登记标签页（keep-alive 的 include 依据标签页生成） */
watch(
  () => route.path,
  () => {
    if (!route.name || route.name === 'notFound' || route.meta.public) {
      return
    }
    worktab.setActive(route.path)
    worktab.addTab({
      path: route.path,
      name: String(route.name),
      title: (route.meta.title as string) || String(route.name),
      icon: route.meta.icon as string | undefined,
    })
  },
  { immediate: true },
)
</script>

<style scoped>
.layout {
  display: flex;
  height: 100vh;
  overflow: hidden;
  background: var(--art-body-bg);
}

.layout-main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.layout-mask {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgb(0 0 0 / 45%);
}
</style>
