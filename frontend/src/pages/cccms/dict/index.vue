<template>
  <div class="art-fill">
    <ArtSplitView aside-width="248px">
      <template #aside>
        <ArtTreePanel
          :title="t('dict.categoryTreeTitle')"
          :data="treeData"
          :current-key="currentId"
          @node-click="onNodeClick"
        >
          <template #header>
            <el-tooltip :content="t('dict.addTopCategory')" placement="top">
              <el-button v-auth="'cccms:dict:category_save'" text circle :icon="Plus" @click="openCategoryCreate(0)" />
            </el-tooltip>
            <!-- 只显示本模块的分类，避免看到附件模块的分类 -->
            <RecycleToggle
              :active="categoryRecycle"
              :label="t('dict.categoryTreeTitle')"
              @toggle="toggleCategoryRecycle"
            />
          </template>

          <template #node="{ data }">
            <span class="cat-node">
              <span class="cat-node-label">{{ data.name }}</span>
              <!-- 虚拟节点（全部 / 未分类）不允许改 -->
              <span v-if="data.id > 0" class="cat-node-actions">
                <!-- 分类回收站：节点操作换成还原 / 彻底删除 -->
                <template v-if="categoryRecycle">
                  <el-icon :title="t('table.restore')" @click.stop="onCategoryRestore(data)"><RefreshLeft /></el-icon>
                  <el-icon :title="t('table.forceDelete')" @click.stop="onCategoryForceDelete(data)">
                    <Delete />
                  </el-icon>
                </template>
                <template v-else>
                  <el-icon :title="t('dict.addSubCategory')" @click.stop="openCategoryCreate(data.id)"
                    ><Plus
                  /></el-icon>
                  <el-icon :title="t('dict.renameCategory')" @click.stop="openCategoryEdit(data)"><Edit /></el-icon>
                  <el-icon :title="t('common.delete')" @click.stop="onCategoryDelete(data)"><Delete /></el-icon>
                </template>
              </span>
            </span>
          </template>
        </ArtTreePanel>
      </template>

      <ArtTable
        :columns="columns"
        :data="list"
        :loading="loading"
        :total="total"
        :recycle="recycle"
        selection
        row-key="id"
        v-model:page="page"
        v-model:limit="limit"
        @refresh="load"
        @search="search"
        @reset="reset"
        @page-change="onPageChange"
        @size-change="onLimitChange"
        @restore="onRestore"
        @force-delete="onForceDelete"
        @selection-change="onSelectionChange"
      >
        <template #search>
          <el-form-item :label="t('dict.name')">
            <el-input v-model="query.name" :placeholder="t('dict.inputPlaceholder')" clearable style="width: 180px" />
          </el-form-item>
        </template>

        <template #toolbar>
          <el-button v-auth="'cccms:dict:save'" type="primary" :icon="Plus" @click="openTypeCreate">
            {{ t('dict.createType') }}
          </el-button>
          <!-- 批量操作：只作用于可见行，越权 / 已删除的 id 由后端跳过并回报 -->
          <el-dropdown :disabled="selected.length === 0" @command="onBatchCommand">
            <el-button :disabled="selected.length === 0">
              {{ selected.length ? t('dict.batchActionCount', { count: selected.length }) : t('dict.batchAction') }}
              <el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item v-if="hasAuth('cccms:dict:batch_status')" command="enable">
                  {{ t('dict.batchEnable') }}
                </el-dropdown-item>
                <el-dropdown-item v-if="hasAuth('cccms:dict:batch_status')" command="disable">
                  {{ t('dict.batchDisable') }}
                </el-dropdown-item>
                <el-dropdown-item v-if="hasAuth('cccms:dict:batch_delete')" command="delete" divided>
                  {{ t('dict.batchDelete') }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <el-tag v-if="currentId" type="info" closable @close="clearNode">
            {{ t('dict.onlyView', { name: currentNodeName }) }}
          </el-tag>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" :label="t('dict.typeName')" @toggle="toggle" />
        </template>

        <template #action="{ row }">
          <el-button v-auth="'cccms:dict:data'" link type="primary" @click="openData(row)">
            {{ t('dict.data') }}
          </el-button>
          <el-button v-auth="'cccms:dict:update'" link type="primary" @click="openTypeEdit(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-popconfirm :title="t('dict.typeDeleteConfirm')" @confirm="onTypeDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:dict:delete'" link type="danger">{{ t('common.delete') }}</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <!-- 分类表单 -->
    <el-dialog
      v-model="categoryVisible"
      :title="categoryForm.id ? t('dict.editCategory') : t('dict.createCategory')"
      width="460px"
      :close-on-click-modal="false"
    >
      <el-form ref="categoryFormRef" :model="categoryForm" :rules="categoryRules" label-width="92px">
        <el-form-item :label="t('dict.parentCategory')" prop="parent_id">
          <el-tree-select
            v-model="categoryForm.parent_id"
            :data="categoryOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('dict.categoryName')" prop="name">
          <el-input v-model="categoryForm.name" :placeholder="t('dict.categoryNamePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dict.sort')" prop="sort">
          <el-input-number v-model="categoryForm.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('dict.remark')" prop="remark">
          <el-input v-model="categoryForm.remark" :placeholder="t('dict.remarkPlaceholder')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="categoryVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitCategory">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 字典类型表单 -->
    <el-dialog
      v-model="typeVisible"
      :title="typeForm.id ? t('dict.editType') : t('dict.createType')"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="typeFormRef" :model="typeForm" :rules="typeRules" label-width="92px">
        <el-form-item :label="t('dict.category')" prop="category_id">
          <el-tree-select
            v-model="typeForm.category_id"
            :data="categorySelectOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('dict.name')" prop="name">
          <el-input v-model="typeForm.name" :placeholder="t('dict.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dict.typeName')" prop="type">
          <el-input v-model="typeForm.type" :placeholder="t('dict.typePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dict.remark')" prop="remark">
          <el-input v-model="typeForm.remark" :placeholder="t('dict.remarkPlaceholder')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="typeVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitType">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 字典数据抽屉 -->
    <el-drawer v-model="dataVisible" :title="t('dict.dataTitle', { name: currentType.name })" size="680px">
      <div class="drawer-toolbar">
        <el-button
          v-if="!dataRecycle"
          v-auth="'cccms:dict:save_data'"
          type="primary"
          :icon="Plus"
          @click="openDataCreate"
        >
          {{ t('dict.createData') }}
        </el-button>
        <!-- 批量操作（字典数据） -->
        <el-dropdown v-if="!dataRecycle" :disabled="selectedData.length === 0" @command="onBatchDataCommand">
          <el-button :disabled="selectedData.length === 0">
            {{
              selectedData.length ? t('dict.batchActionCount', { count: selectedData.length }) : t('dict.batchAction')
            }}
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-if="hasAuth('cccms:dict:batch_status_data')" command="enable">
                {{ t('dict.batchEnable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:dict:batch_status_data')" command="disable">
                {{ t('dict.batchDisable') }}
              </el-dropdown-item>
              <el-dropdown-item v-if="hasAuth('cccms:dict:batch_delete_data')" command="delete" divided>
                {{ t('dict.batchDelete') }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <RecycleToggle :active="dataRecycle" :label="t('dict.data')" @toggle="toggleDataRecycle" />
      </div>
      <el-table
        v-loading="dataLoading"
        :data="dataList"
        row-key="id"
        stripe
        border
        max-height="460"
        @selection-change="onDataSelectionChange"
      >
        <el-table-column type="selection" :selectable="() => !dataRecycle" width="46" />
        <el-table-column prop="label" :label="t('dict.label')" min-width="140" />
        <el-table-column prop="value" :label="t('dict.value')" min-width="120" />
        <el-table-column prop="sort" :label="t('dict.sort')" width="80" align="center" />
        <el-table-column :label="t('dict.status')" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
              {{ row.status === 1 ? t('dict.enable') : t('dict.disable') }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="t('table.action')" width="130" fixed="right">
          <template #default="{ row }">
            <template v-if="dataRecycle">
              <el-button v-auth="'cccms:recycle:restore'" link type="primary" @click="onDataRestore(row)">
                {{ t('table.restore') }}
              </el-button>
              <el-button v-auth="'cccms:recycle:delete'" link type="danger" @click="onDataForceDelete(row)">
                {{ t('table.forceDelete') }}
              </el-button>
            </template>
            <template v-else>
              <el-button v-auth="'cccms:dict:update_data'" link type="primary" @click="openDataEdit(row)">
                {{ t('common.edit') }}
              </el-button>
              <el-popconfirm :title="t('dict.dataDeleteConfirm')" @confirm="onDataDelete(row.id)">
                <template #reference>
                  <el-button v-auth="'cccms:dict:delete_data'" link type="danger">{{ t('common.delete') }}</el-button>
                </template>
              </el-popconfirm>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <el-dialog
        v-model="dataFormVisible"
        :title="dataForm.id ? t('dict.editData') : t('dict.createData')"
        width="440px"
        append-to-body
      >
        <el-form ref="dataFormRef" :model="dataForm" :rules="dataRules" label-width="80px">
          <el-form-item :label="t('dict.label')" prop="label">
            <el-input v-model="dataForm.label" />
          </el-form-item>
          <el-form-item :label="t('dict.value')" prop="value">
            <el-input v-model="dataForm.value" />
          </el-form-item>
          <el-form-item :label="t('dict.sort')" prop="sort">
            <el-input-number v-model="dataForm.sort" :min="0" />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="dataFormVisible = false">{{ t('common.cancel') }}</el-button>
          <el-button type="primary" :loading="saving" @click="submitData">{{ t('common.confirm') }}</el-button>
        </template>
      </el-dialog>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:dict' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { ArrowDown, Delete, Edit, Plus, RefreshLeft } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { useTable } from '@/composables/useTable'
import { useRecycle } from '@/composables/useRecycle'
import { useUserStore } from '@/stores/user'
import { categoryDelete, categorySave, categoryTree, categoryUpdate, type CategoryNode } from '@/api/category'
import {
  dictDataBatchDelete,
  dictDataBatchStatus,
  dictDataDelete,
  dictDataList,
  dictDataSave,
  dictDataUpdate,
  dictTypeBatchDelete,
  dictTypeBatchStatus,
  dictTypeDelete,
  dictTypeList,
  dictTypeSave,
  dictTypeUpdate,
  type DictBatchResult,
} from '@/api/dict'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })
/**
 * 批量操作菜单项用 `v-if="hasAuth(...)"` 而不是 `v-auth`：
 * ElDropdownItem 的根节点不是单个元素（内部是 ElRovingFocusItem），
 * 运行时指令挂不上去，Vue 会告警且权限隐藏失效。
 */
const { hasAuth } = useUserStore()

const MODULE = 'dict' as const
/** 虚拟节点：全部 / 未分类（后端约定 category_id：0=全部，-1=未分类） */
const ALL_ID = 0
const NONE_ID = -1

interface Row {
  id: number
  name?: string
  type?: string
  category_id?: number
  category_name?: string
  remark?: string
  label?: string
  value?: string
  sort?: number
  [key: string]: unknown
}

interface Query {
  name: string
}

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'name', label: t('dict.name'), minWidth: 150 },
  { prop: 'type', label: t('dict.typeName'), minWidth: 150 },
  { prop: 'category_name', label: t('dict.category'), width: 140 },
  { prop: 'remark', label: t('dict.remark'), minWidth: 160 },
  { prop: 'action', label: t('table.action'), width: 230, fixed: 'right', slot: 'action', lockVisible: true },
])

/* ---- 左侧分类树 ---- */
const saving = ref(false)
const currentId = ref(0)
const categories = ref<CategoryNode[]>([])

const treeData = computed(() =>
  // 回收站视图里只列已删分类，不掺「全部 / 未分类」这两个虚拟节点
  categoryRecycle.value
    ? categories.value
    : [{ id: ALL_ID, name: t('dict.all') }, { id: NONE_ID, name: t('dict.uncategorized') }, ...categories.value],
)

const currentNodeName = computed(() => {
  if (currentId.value === ALL_ID) {
    return t('dict.all')
  }
  if (currentId.value === NONE_ID) {
    return t('dict.uncategorized')
  }
  return findCategory(categories.value, currentId.value)?.name ?? ''
})

/** 上级分类选择器：顶层补一个「顶级分类」 */
const categoryOptions = computed(() => [{ id: 0, name: t('dict.topCategory'), children: categories.value }])
/** 类型归属选择器：顶层补一个「未分类」 */
const categorySelectOptions = computed(() => [{ id: 0, name: t('dict.uncategorized'), children: categories.value }])

function findCategory(nodes: CategoryNode[], id: number): CategoryNode | null {
  for (const node of nodes) {
    if (node.id === id) {
      return node
    }
    if (node.children?.length) {
      const hit = findCategory(node.children, id)
      if (hit) {
        return hit
      }
    }
  }
  return null
}

async function loadCategories(): Promise<void> {
  categories.value = await categoryTree(MODULE, categoryRecycle.value)
  if (currentId.value > 0 && !findCategory(categories.value, currentId.value)) {
    currentId.value = ALL_ID
  }
}

/* ---- 回收站：本页三个入口（字典类型 / 字典数据 / 分类）各一个开关 ---- */
// 声明在 useTable 之前：列表闭包在 setup 阶段就会执行一次
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('dict_type', {
  reload: () => search(),
})
const {
  recycle: categoryRecycle,
  toggle: toggleCategoryRecycle,
  onRestore: onCategoryRestore,
  onForceDelete: onCategoryForceDelete,
} = useRecycle('category', { reload: () => loadCategories() })
const {
  recycle: dataRecycle,
  toggle: toggleDataRecycle,
  onRestore: onDataRestore,
  onForceDelete: onDataForceDelete,
} = useRecycle('dict_data', { reload: () => loadData() })

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  Row,
  Query
>({
  // category_id 在请求时注入：避免「重置」把树上的筛选一起清掉
  api: (params) => dictTypeList({ ...params, category_id: currentId.value, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '' },
})

function onNodeClick(data: Record<string, any>): void {
  currentId.value = Number(data.id ?? ALL_ID)
  search()
}

function clearNode(): void {
  currentId.value = ALL_ID
  search()
}

async function refresh(): Promise<void> {
  await Promise.all([load(), loadCategories()])
}

/* ---- 分类表单 ---- */
const categoryFormRef = ref<FormInstance>()
const categoryVisible = ref(false)
const emptyCategoryForm = { id: 0, parent_id: 0, name: '', sort: 0, remark: '' }
const categoryForm = reactive<Record<string, any>>({ ...emptyCategoryForm })
const categoryRules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('dict.categoryNameRequired'), trigger: 'blur' }],
}))

function openCategoryCreate(parentId: number): void {
  Object.assign(categoryForm, emptyCategoryForm, { parent_id: parentId })
  categoryVisible.value = true
}

function openCategoryEdit(record: CategoryNode): void {
  Object.assign(categoryForm, emptyCategoryForm, record)
  categoryVisible.value = true
}

async function submitCategory(): Promise<void> {
  const valid = await categoryFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (categoryForm.id) {
      await categoryUpdate(MODULE, { ...categoryForm })
    } else {
      await categorySave(MODULE, { ...categoryForm })
    }
    ElMessage.success(t('dict.saveSuccess'))
    categoryVisible.value = false
    await refresh()
  } finally {
    saving.value = false
  }
}

async function onCategoryDelete(record: CategoryNode): Promise<void> {
  try {
    await ElMessageBox.confirm(t('dict.categoryDeleteConfirm', { name: record.name }), t('dict.deleteCategoryTitle'), {
      type: 'warning',
    })
  } catch {
    return
  }
  await categoryDelete(MODULE, record.id)
  ElMessage.success(t('dict.deleteSuccess'))
  await refresh()
}

/* ---- 字典类型 ---- */
const typeFormRef = ref<FormInstance>()
const typeVisible = ref(false)
const emptyTypeForm = { id: 0, category_id: 0, name: '', type: '', remark: '' }
const typeForm = reactive<Record<string, any>>({ ...emptyTypeForm })
const typeRules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('dict.nameRequired'), trigger: 'blur' }],
  type: [{ required: true, message: t('dict.typeRequired'), trigger: 'blur' }],
}))

function openTypeCreate(): void {
  // 左侧选了具体分类时，新增默认归到该分类
  const categoryId = currentId.value > 0 ? currentId.value : 0
  Object.assign(typeForm, emptyTypeForm, { category_id: categoryId })
  typeVisible.value = true
}

function openTypeEdit(record: Row): void {
  Object.assign(typeForm, emptyTypeForm, record)
  typeVisible.value = true
}

async function submitType(): Promise<void> {
  const valid = await typeFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (typeForm.id) {
      await dictTypeUpdate({ ...typeForm })
    } else {
      await dictTypeSave({ ...typeForm })
    }
    ElMessage.success(t('dict.saveSuccess'))
    typeVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onTypeDelete(id: number): Promise<void> {
  await dictTypeDelete(id)
  ElMessage.success(t('dict.deleteSuccess'))
  load()
}

/* ---- 字典类型批量操作 ---- */
const selected = ref<Row[]>([])

function onSelectionChange(rows: Row[]): void {
  selected.value = rows
}

type BatchAction = 'enable' | 'disable' | 'delete'

/** 批量结果提示：整句走 i18n key，避免用 t() 拼字符串 */
const batchMessageKeys: Record<BatchAction, { done: string; skipped: string }> = {
  enable: { done: 'dict.batchEnableDone', skipped: 'dict.batchEnableSkipped' },
  disable: { done: 'dict.batchDisableDone', skipped: 'dict.batchDisableSkipped' },
  delete: { done: 'dict.batchDeleteDone', skipped: 'dict.batchDeleteSkipped' },
}

/** 越权 / 已删除的 id 由后端跳过，这里统一回报 */
function reportBatch(result: DictBatchResult, action: BatchAction): void {
  const keys = batchMessageKeys[action]
  if (result.skipped.length > 0) {
    ElMessage.warning(t(keys.skipped, { affected: result.affected, skipped: result.skipped.length }))
  } else {
    ElMessage.success(t(keys.done, { count: result.affected }))
  }
}

async function onBatchCommand(command: string): Promise<void> {
  const ids = selected.value.map((row) => row.id)
  if (ids.length === 0) {
    return
  }
  if (command === 'enable' || command === 'disable') {
    const result = await dictTypeBatchStatus(ids, command === 'enable' ? 1 : 0)
    reportBatch(result, command === 'enable' ? 'enable' : 'disable')
    load()
    return
  }
  if (command === 'delete') {
    try {
      await ElMessageBox.confirm(t('dict.batchTypeDeleteConfirm', { count: ids.length }), t('dict.batchDelete'), {
        type: 'warning',
      })
    } catch {
      return
    }
    const result = await dictTypeBatchDelete(ids)
    reportBatch(result, 'delete')
    load()
  }
}

/* ---- 字典数据 ---- */
const dataVisible = ref(false)
const dataLoading = ref(false)
const dataList = ref<Row[]>([])
const currentType = reactive<{ id: number; name: string }>({ id: 0, name: '' })

async function openData(record: Row): Promise<void> {
  currentType.id = record.id
  currentType.name = record.name ?? ''
  selectedData.value = []
  dataVisible.value = true
  await loadData()
}

async function loadData(): Promise<void> {
  dataLoading.value = true
  try {
    dataList.value = (await dictDataList(currentType.id, dataRecycle.value)) as unknown as Row[]
  } finally {
    dataLoading.value = false
  }
}

const dataFormRef = ref<FormInstance>()
const dataFormVisible = ref(false)
const emptyDataForm = { id: 0, type_id: 0, label: '', value: '', sort: 0 }
const dataForm = reactive<Record<string, any>>({ ...emptyDataForm })
const dataRules = computed<FormRules>(() => ({
  label: [{ required: true, message: t('dict.labelRequired'), trigger: 'blur' }],
  value: [{ required: true, message: t('dict.valueRequired'), trigger: 'blur' }],
}))

function openDataCreate(): void {
  Object.assign(dataForm, emptyDataForm, { type_id: currentType.id })
  dataFormVisible.value = true
}

function openDataEdit(record: Row): void {
  Object.assign(dataForm, emptyDataForm, record)
  dataFormVisible.value = true
}

async function submitData(): Promise<void> {
  const valid = await dataFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    if (dataForm.id) {
      await dictDataUpdate({ ...dataForm })
    } else {
      await dictDataSave({ ...dataForm })
    }
    ElMessage.success(t('dict.saveSuccess'))
    dataFormVisible.value = false
    await loadData()
  } finally {
    saving.value = false
  }
}

async function onDataDelete(id: number): Promise<void> {
  await dictDataDelete(id)
  ElMessage.success(t('dict.deleteSuccess'))
  await loadData()
}

/* ---- 字典数据批量操作 ---- */
const selectedData = ref<Row[]>([])

function onDataSelectionChange(rows: Row[]): void {
  selectedData.value = rows
}

async function onBatchDataCommand(command: string): Promise<void> {
  const ids = selectedData.value.map((row) => row.id)
  if (ids.length === 0) {
    return
  }
  if (command === 'enable' || command === 'disable') {
    const result = await dictDataBatchStatus(ids, command === 'enable' ? 1 : 0)
    reportBatch(result, command === 'enable' ? 'enable' : 'disable')
    await loadData()
    return
  }
  if (command === 'delete') {
    try {
      await ElMessageBox.confirm(t('dict.batchDataDeleteConfirm', { count: ids.length }), t('dict.batchDelete'), {
        type: 'warning',
      })
    } catch {
      return
    }
    const result = await dictDataBatchDelete(ids)
    reportBatch(result, 'delete')
    await loadData()
  }
}

loadCategories()
</script>

<style scoped>
.drawer-toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

/* ---- 分类树节点 ---- */
.cat-node {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.cat-node-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cat-node-actions {
  display: flex;
  gap: 6px;
  align-items: center;
  opacity: 0;
  transition: opacity 0.15s ease;
}

.cat-node:hover .cat-node-actions {
  opacity: 1;
}

.cat-node-actions :deep(.el-icon) {
  font-size: 13px;
  color: var(--art-muted);
  cursor: pointer;
}

.cat-node-actions :deep(.el-icon:hover) {
  color: var(--el-color-primary);
}
</style>
