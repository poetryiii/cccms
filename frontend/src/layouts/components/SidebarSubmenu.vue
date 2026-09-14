<template>
  <template v-for="m in items" :key="m.id">
    <el-sub-menu v-if="hasChildren(m)" :index="String(m.id)">
      <template #title>
        <el-icon><ArtIcon :name="m.icon" /></el-icon>
        <span>{{ m.title }}</span>
      </template>
      <SidebarSubmenu :menus="m.children || []" />
    </el-sub-menu>

    <el-menu-item v-else :index="m.path">
      <el-icon><ArtIcon :name="m.icon" /></el-icon>
      <template #title>{{ m.title }}</template>
    </el-menu-item>
  </template>
</template>

<script setup lang="ts">
/** 侧边栏菜单递归渲染：目录(type=1) 渲染为子菜单，菜单(type=2) 渲染为菜单项。 */
import { computed } from 'vue'
import ArtIcon from '@/components/core/ArtIcon.vue'
import type { MenuNode } from '@/api/types'

const props = defineProps<{ menus: MenuNode[] }>()

/** 按钮节点(type=3)不进侧边栏 */
const items = computed(() => props.menus.filter((m) => m.type !== 3))

function hasChildren(m: MenuNode): boolean {
  return (m.children || []).some((c) => c.type !== 3)
}
</script>
