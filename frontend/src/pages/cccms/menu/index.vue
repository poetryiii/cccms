<template>
  <div class="art-fill">
    <ArtTable
      :columns="columns"
      :data="list"
      :loading="loading"
      :pagination="false"
      :recycle="recycle"
      tree
      @refresh="load"
      @restore="onRestore"
      @force-delete="onForceDelete"
    >
      <template #toolbar>
        <el-button v-auth="'cccms:menu:save'" type="primary" :icon="Plus" @click="openCreate(0)">
          {{ t('common.create') }}
        </el-button>
        <span class="toolbar-tip">
          {{ t('menu.permTip') }}
        </span>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" :label="t('menu.menuLabel')" @toggle="toggle" />
      </template>

      <template #type="{ row }">
        <el-tag :type="typeTag(row.type)" effect="light">{{ typeText(row.type) }}</el-tag>
      </template>

      <template #node="{ row }">
        <code class="node-code">{{ row.node }}</code>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? t('menu.shown') : t('menu.hidden') }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button v-if="row.type !== 3" v-auth="'cccms:menu:save'" link type="primary" @click="openCreate(row.id)">
          {{ t('menu.addChild') }}
        </el-button>
        <el-button v-auth="'cccms:menu:update'" link type="primary" @click="openEdit(row)">
          {{ t('common.edit') }}
        </el-button>
        <el-popconfirm :title="t('menu.deleteConfirm')" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:menu:delete'" link type="danger">{{ t('common.delete') }}</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('menu.editTitle') : t('menu.createTitle')"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item :label="t('menu.parentNode')" prop="parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentOptions"
            :props="{ label: 'title', children: 'children' }"
            node-key="id"
            check-strictly
            :placeholder="t('menu.top')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('menu.typeLabel')" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">{{ t('menu.typeDir') }}</el-radio>
            <el-radio :value="2">{{ t('menu.typeMenu') }}</el-radio>
            <el-radio :value="3">{{ t('menu.typeButton') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('menu.name')" prop="title">
          <el-input v-model="form.title" :placeholder="t('menu.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('menu.node')" prop="node">
          <el-input v-model="form.node" :placeholder="t('menu.nodePlaceholder')" />
          <div class="form-tip">{{ t('menu.nodeTip') }}</div>
        </el-form-item>
        <el-form-item v-if="form.type !== 3" :label="t('menu.path')" prop="path">
          <el-input v-model="form.path" :placeholder="t('menu.pathPlaceholder')" />
        </el-form-item>
        <el-form-item v-if="form.type === 2" :label="t('menu.component')" prop="component">
          <el-input v-model="form.component" :placeholder="t('menu.componentPlaceholder')" />
        </el-form-item>
        <el-form-item v-if="form.type !== 3" :label="t('menu.icon')" prop="icon">
          <el-popover trigger="click" :width="372" placement="bottom-start">
            <template #reference>
              <el-input v-model="form.icon" readonly :placeholder="t('menu.iconPlaceholder')" style="width: 100%">
                <template #prefix>
                  <el-icon><ArtIcon :name="form.icon" /></el-icon>
                </template>
              </el-input>
            </template>
            <div class="icon-picker">
              <button
                v-for="name in MENU_ICON_OPTIONS"
                :key="name"
                type="button"
                class="icon-picker-item"
                :class="{ 'is-active': form.icon === name }"
                :title="name"
                @click="form.icon = name"
              >
                <el-icon :size="16"><ArtIcon :name="name" /></el-icon>
              </button>
            </div>
          </el-popover>
        </el-form-item>
        <el-form-item :label="t('menu.sort')" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('menu.status')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('menu.shown') }}</el-radio>
            <el-radio :value="0">{{ t('menu.hidden') }}</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:menu' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtIcon from '@/components/core/ArtIcon.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { menuDelete, menuSave, menuTree, menuUpdate } from '@/api/menu'
import { MENU_ICON_OPTIONS } from '@/utils/icon'
import type { MenuNode } from '@/api/types'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

/** 类型枚举：值是 i18n key，渲染处翻译 */
const typeTextMap: Record<number, string> = {
  1: 'menu.typeDir',
  2: 'menu.typeMenu',
  3: 'menu.typeButton',
}
const typeTagMap: Record<number, 'primary' | 'success' | 'warning'> = {
  1: 'primary',
  2: 'success',
  3: 'warning',
}

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'title', label: t('menu.name'), minWidth: 200 },
  { prop: 'type', label: t('menu.typeLabel'), width: 90, align: 'center', slot: 'type' },
  { prop: 'node', label: t('menu.node'), minWidth: 190, slot: 'node' },
  { prop: 'path', label: t('menu.path'), width: 160 },
  { prop: 'component', label: t('menu.component'), width: 190, defaultHidden: true },
  { prop: 'sort', label: t('menu.sort'), width: 80, align: 'center' },
  { prop: 'status', label: t('menu.status'), width: 90, align: 'center', slot: 'status' },
  { prop: 'action', label: t('table.action'), width: 210, fixed: 'right', slot: 'action', lockVisible: true },
])

const loading = ref(false)
const list = ref<MenuNode[]>([])
const parentOptions = ref<MenuNode[]>([])

const typeText = (type: number): string => (typeTextMap[type] ? t(typeTextMap[type]) : '-')
const typeTag = (type: number): 'primary' | 'success' | 'warning' => typeTagMap[type] ?? 'primary'

// 回收站开关（load() 在本文件末尾首次执行，那时它已初始化）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('menu', {
  reload: () => load(),
})

async function load(): Promise<void> {
  loading.value = true
  try {
    // trashed=true → 后端返回平铺的已删节点（含隐藏节点），恢复后父子关系自动接上
    list.value = await menuTree(recycle.value)
    parentOptions.value = [{ id: 0, title: t('menu.top'), children: list.value } as unknown as MenuNode]
  } finally {
    loading.value = false
  }
}

const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  parent_id: 0,
  type: 2,
  title: '',
  node: '',
  path: '',
  component: '',
  icon: '',
  sort: 0,
  status: 1,
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules = computed<FormRules>(() => ({
  title: [{ required: true, message: t('menu.nameRequired'), trigger: 'blur' }],
  node: [{ required: true, message: t('menu.nodeRequired'), trigger: 'blur' }],
}))

function openCreate(parentId: number): void {
  Object.assign(form, emptyForm, { parent_id: parentId })
  formVisible.value = true
}

function openEdit(record: MenuNode): void {
  Object.assign(form, emptyForm, record)
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (form.id) {
      await menuUpdate({ ...form })
    } else {
      await menuSave({ ...form })
    }
    ElMessage.success(t('menu.saveSuccess'))
    formVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await menuDelete(id)
  ElMessage.success(t('menu.deleteSuccess'))
  await load()
}

load()
</script>

<style scoped>
.toolbar-tip {
  font-size: 12px;
  color: var(--art-muted);
}

.node-code {
  padding: 1px 6px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-sub);
  background: var(--art-hover-bg);
  border-radius: 4px;
}

.form-tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}

.icon-picker {
  display: grid;
  grid-template-columns: repeat(9, 1fr);
  gap: 6px;
  max-height: 260px;
  overflow-y: auto;
}

.icon-picker-item {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  color: var(--art-sub);
  cursor: pointer;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
}

.icon-picker-item:hover {
  color: var(--el-color-primary);
  background: var(--art-hover-bg);
}

.icon-picker-item.is-active {
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary-light-7);
}
</style>
