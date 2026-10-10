<template>
  <div class="layout-content">
    <div class="layout-content-inner">
      <router-view v-slot="{ Component, route }">
        <transition name="fade-slide" mode="out-in" appear>
          <keep-alive :include="worktab.cached" :max="15">
            <component :is="Component" :key="worktab.keyOf(route.path)" />
          </keep-alive>
        </transition>
      </router-view>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * 内容区：keep-alive 的 include 取「已打开标签的组件名」，
 * 于是关闭标签 = 从名单移除 = 实例真正销毁，内存不会无限增长。
 *
 * 组件 key 由 worktab.keyOf(path) 生成（路径 + 刷新序号）：只改 include 无法让
 * 正在显示的实例重新挂载，「刷新」还需要 key 变化配合，详见 worktab.refresh()。
 *
 * 注意：页面组件必须用 `defineOptions({ name: '<菜单 slug>' })` 声明组件名，
 * 否则 include 匹配不上（页面文件都叫 index.vue，推断出的名字会互相撞车）。
 *
 * 还有一条同样硬的约束：**页面模板必须是单根元素**。
 *
 * 若在 `<template>` 顶层先写一段注释（或任何第二个节点），页面就成了多根
 * （Fragment）组件，而 `mode="out-in"` 的 `<Transition>` 处理不了多根子节点。
 * 现象很有迷惑性：**点菜单切页时 URL 变了但内容区一片空白，按 F5 刷新却能正常加载**
 * —— 因为 F5 走的是初始挂载（不经过 out-in 的「先离开、再进入」状态机），
 * 而 SPA 切页必须走完整的离开 → 进入流程。排查时容易误判成路由或接口问题。
 *
 * 顶层说明文字请写进根元素内部（`<div>` 里第一行即可）。
 *
 * 内容区自身不滚动，滚动交给页面：列表页用 .art-fill，普通页用 .art-scroll。
 */
import { useWorktabStore } from '@/stores/worktab'

const worktab = useWorktabStore()
</script>

<style scoped>
.layout-content {
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.layout-content-inner {
  width: 100%;
  max-width: var(--art-container-width);
  height: 100%;
  padding: 16px;
  margin: 0 auto;
}
</style>
