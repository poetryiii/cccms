<template>
  <aside
    class="sidebar"
    :class="{ 'is-collapsed': collapsed, 'is-mobile': mobile, 'is-open': mobile && !collapsed }"
    :style="{ width: mobile ? '220px' : sidebarWidth }"
  >
    <div class="sidebar-logo" @click="goHome">
      <img v-if="appStore.logo" class="sidebar-logo-img" :src="appStore.logo" alt="logo" />
      <div v-else class="sidebar-logo-mark">{{ brandInitial }}</div>
      <span v-show="!collapsed || mobile" class="sidebar-logo-text">{{ appStore.systemName }}</span>
    </div>

    <el-scrollbar class="sidebar-body">
      <el-menu
        ref="menuRef"
        :default-active="activeMenu"
        :collapse="!mobile && collapsed"
        :collapse-transition="false"
        unique-opened
        @select="onSelect"
      >
        <SidebarSubmenu :menus="menuStore.menus" />
      </el-menu>
    </el-scrollbar>

    <!-- 备案号 / 版权来自后台配置 -->
    <footer
      v-if="(!collapsed || mobile) && (appStore.icp || appStore.copyright)"
      class="sidebar-footer"
    >
      <div v-if="appStore.copyright" class="sidebar-footer-line">{{ appStore.copyright }}</div>
      <div v-if="appStore.icp" class="sidebar-footer-line">{{ appStore.icp }}</div>
    </footer>
  </aside>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SidebarSubmenu from './SidebarSubmenu.vue'
import { useAppStore } from '@/stores/app'
import { HOME_PATH } from '@/stores/worktab'
import { useMenuStore } from '@/stores/menu'
import type { MenuNode } from '@/api/types'

const props = defineProps<{
  collapsed: boolean
  mobile?: boolean
}>()

const route = useRoute()
const router = useRouter()
const menuStore = useMenuStore()
const appStore = useAppStore()

const brandInitial = computed(() => (appStore.systemName || 'C').charAt(0).toUpperCase())

/**
 * el-menu 通过 expose 暴露了 open(index)，内部会用正确的 indexPath 展开整条链，
 * 因此这里只需要传「目录 id」。注意 index 必须是已注册的子菜单，否则会抛错。
 */
const menuRef = ref()

const activeMenu = computed(() => route.path)
const sidebarWidth = computed(() => `${props.collapsed ? 64 : 220}px`)

/** 回溯出当前路径的所有祖先目录 id */
function findChain(menus: MenuNode[], path: string, chain: string[]): string[] | null {
  for (const m of menus) {
    if (m.type === 2 && m.path === path) {
      return chain
    }
    if (m.children?.length) {
      const hit = findChain(m.children, path, [...chain, String(m.id)])
      if (hit) {
        return hit
      }
    }
  }
  return null
}

/**
 * el-menu 只在 items 变化时自动展开当前项（内部 watch(items, initMenu)），
 * 路由切换时不会，所以要手动补一次。
 */
function syncOpenMenus(): void {
  nextTick(() => {
    const chain = findChain(menuStore.menus, activeMenu.value, []) ?? []
    chain.forEach((id) => menuRef.value?.open(id))
  })
}

watch(activeMenu, syncOpenMenus)
watch(() => menuStore.menus, syncOpenMenus)
onMounted(syncOpenMenus)

function onSelect(index: string): void {
  if (index.startsWith('/') && index !== route.path) {
    router.push(index)
  }
}

function goHome(): void {
  router.push(HOME_PATH)
}
</script>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  height: 100%;
  overflow: hidden;
  background: var(--art-sidebar-bg);
  border-right: 1px solid var(--art-card-border);
  transition: width 0.2s ease;
}

.sidebar.is-mobile {
  position: fixed;
  top: 0;
  bottom: 0;
  left: 0;
  z-index: 1001;
  transform: translateX(-100%);
  transition: transform 0.25s ease;
}

.sidebar.is-mobile.is-open {
  transform: translateX(0);
}

.sidebar-logo {
  display: flex;
  flex-shrink: 0;
  gap: 8px;
  align-items: center;
  /* 盾牌 + 系统名整体居中（折叠时只剩盾牌，也是居中的） */
  justify-content: center;
  height: var(--art-header-height);
  padding: 0 16px;
  overflow: hidden;
  cursor: pointer;
}

/* 兜底字母标：尺寸与 logo 图保持一致 */
.sidebar-logo-mark {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
  background: var(--art-primary);
  border-radius: 6px;
}

.sidebar-logo-text {
  font-size: 16px;
  font-weight: 700;
  /* 字距会在末尾多出一格，用负 margin 抵掉，居中的才是视觉中心 */
  margin-right: -1px;
  letter-spacing: 1px;
  color: var(--art-main);
  white-space: nowrap;
}

.sidebar-logo-img {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  object-fit: contain;
  border-radius: 6px;
}

.sidebar-body {
  flex: 1;
  min-height: 0;
}

.sidebar-footer {
  flex-shrink: 0;
  padding: 10px 14px 14px;
  font-size: 11px;
  line-height: 1.6;
  color: var(--art-muted);
  text-align: center;
  border-top: 1px solid var(--art-card-border);
}

.sidebar-footer-line {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar :deep(.el-menu) {
  padding: 6px 8px;
  background: transparent;
  border-right: none;
}

.sidebar :deep(.el-menu-item),
.sidebar :deep(.el-sub-menu__title) {
  height: 42px;
  margin-bottom: 2px;
  line-height: 42px;
  border-radius: calc(var(--art-radius) - 2px);
}

.sidebar :deep(.el-menu-item.is-active) {
  font-weight: 600;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.sidebar :deep(.el-menu--collapse) {
  padding: 6px 4px;
}
</style>
