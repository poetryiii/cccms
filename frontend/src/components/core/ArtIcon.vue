<template>
  <i :class="iconClass" />
</template>

<script setup lang="ts">
/**
 * 按名字渲染 RemixIcon（只渲染图标本身，外层自行用 <el-icon> 包裹或控制字号）。
 *
 *   <el-icon><ArtIcon name="icon-user" /></el-icon>
 *   <ArtIcon name="ri-user-line" />
 */
import { computed } from 'vue'
import { resolveIcon } from '@/utils/icon'

const props = defineProps<{ name?: string }>()

const iconClass = computed(() => resolveIcon(props.name))
</script>
