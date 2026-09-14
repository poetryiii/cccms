<template>
  <component :is="icon" />
</template>

<script setup lang="ts">
/**
 * 按名字渲染图标（只渲染图标本身，外层自行用 <el-icon> 包裹）。
 *
 *   <el-icon><ArtIcon name="icon-user" /></el-icon>
 */
import { computed } from 'vue'
import { resolveIcon } from '@/utils/icon'

const props = defineProps<{ name?: string }>()

const icon = computed(() => resolveIcon(props.name))
</script>
