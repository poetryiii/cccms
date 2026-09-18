<template>
  <el-card class="art-tree-panel" shadow="never">
    <template #header>
      <div class="art-tree-panel-head">
        <span class="art-tree-panel-title">{{ title }}</span>
        <div class="art-tree-panel-actions">
          <slot name="header" />
        </div>
      </div>
    </template>

    <el-input
      v-if="filterable"
      v-model="keyword"
      :prefix-icon="Search"
      placeholder="搜索名称"
      clearable
      size="small"
      class="art-tree-panel-filter"
    />

    <div class="art-tree-panel-body">
      <el-tree
        ref="treeRef"
        :data="data"
        :node-key="nodeKey"
        :props="treeProps"
        :filter-node-method="filterNode"
        :default-expand-all="expandAll"
        :expand-on-click-node="false"
        highlight-current
        @node-click="onNodeClick"
      >
        <template #default="scope">
          <!-- 默认显示 labelKey 字段；页面可用 #node 插槽自定义（如加悬浮操作按钮） -->
          <slot name="node" v-bind="scope">{{ scope.data[labelKey] }}</slot>
        </template>
      </el-tree>
    </div>
  </el-card>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { ElTree } from 'element-plus'
import { Search } from '@element-plus/icons-vue'

/**
 * 左侧树面板：搜索 + 树 + 高亮当前节点。
 *
 * 只负责渲染与交互，数据加载和增删改由页面负责，
 * 这样部门/角色/字典分类/附件分类四个场景可以共用同一套外观。
 */
const props = withDefaults(
  defineProps<{
    data: Record<string, any>[]
    title?: string
    nodeKey?: string
    labelKey?: string
    filterable?: boolean
    expandAll?: boolean
    /** 当前选中节点的 key，用于高亮 */
    currentKey?: string | number | null
  }>(),
  {
    title: '',
    nodeKey: 'id',
    labelKey: 'name',
    filterable: true,
    expandAll: true,
    currentKey: null,
  },
)

const emit = defineEmits<{ 'node-click': [data: Record<string, any>] }>()

const treeRef = ref<InstanceType<typeof ElTree>>()
const keyword = ref('')

const treeProps = computed(() => ({ label: props.labelKey, children: 'children' }))

function filterNode(value: string, data: Record<string, any>): boolean {
  if (!value) {
    return true
  }
  return String(data[props.labelKey] ?? '')
    .toLowerCase()
    .includes(value.toLowerCase())
}

watch(keyword, (value) => {
  treeRef.value?.filter(value)
})

function syncCurrentKey(): void {
  treeRef.value?.setCurrentKey(props.currentKey === null ? undefined : props.currentKey)
}

watch(
  () => props.currentKey,
  () => void nextTick(syncCurrentKey),
)
onMounted(syncCurrentKey)

function onNodeClick(data: Record<string, any>): void {
  emit('node-click', data)
}
</script>

<style scoped>
.art-tree-panel {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.art-tree-panel :deep(.el-card__header) {
  padding: 12px 14px;
}

.art-tree-panel :deep(.el-card__body) {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
  padding: 10px;
}

.art-tree-panel-head {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
}

.art-tree-panel-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--art-main);
}

.art-tree-panel-actions {
  display: flex;
  gap: 2px;
  align-items: center;
}

.art-tree-panel-filter {
  flex-shrink: 0;
  margin-bottom: 8px;
}

.art-tree-panel-body {
  flex: 1;
  min-height: 0;
  overflow: auto;
}

.art-tree-panel :deep(.el-tree-node__content) {
  height: 34px;
}
</style>
