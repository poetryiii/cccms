<template>
  <el-tooltip :content="active ? '退出回收站' : `${label}回收站`" placement="top">
    <el-button
      v-auth="['cccms:recycle:restore', 'cccms:recycle:delete']"
      text
      circle
      class="recycle-toggle"
      :class="{ 'is-active': active }"
      :icon="Delete"
      @click="emit('toggle')"
    />
  </el-tooltip>
</template>

<script setup lang="ts">
import { Delete } from '@element-plus/icons-vue'

/**
 * 模块页表格右上角的「回收站」开关。
 *
 * 点击后当前表格改为查已删数据（不是打开新页面/弹窗），激活时图标高亮。
 * 可见性挂在两个回收站权限上：没有任何一个，进来也没东西可点。
 */
withDefaults(defineProps<{ active: boolean; label?: string }>(), { label: '' })

const emit = defineEmits<{ toggle: [] }>()
</script>

<style scoped>
.recycle-toggle.is-active {
  color: var(--art-primary);
  background: var(--art-primary-light, var(--el-color-primary-light-9));
}
</style>
