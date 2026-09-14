<template>
  <div class="art-split" :style="style">
    <div class="art-split-aside">
      <slot name="aside" />
    </div>
    <div class="art-split-main">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

/**
 * 左右两栏布局容器（左：树/分类导航，右：表格或表单）。
 *
 * 与「配置管理」页的两栏结构保持一致；窄屏自动改为上下堆叠。
 */
const props = withDefaults(defineProps<{ asideWidth?: string }>(), {
  asideWidth: '248px',
})

const style = computed(() => ({ '--art-split-aside': props.asideWidth }) as Record<string, string>)
</script>

<style scoped>
.art-split {
  display: flex;
  flex: 1;
  gap: 12px;
  min-height: 0;
}

.art-split-aside {
  display: flex;
  flex: 0 0 var(--art-split-aside);
  min-height: 0;
}

.art-split-main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
}

@media (max-width: 900px) {
  .art-split {
    flex-direction: column;
    overflow-y: auto;
  }

  .art-split-aside {
    flex: 0 0 auto;
    max-height: 260px;
  }

  .art-split-main {
    flex: 1 0 auto;
  }
}
</style>
