<template>
  <div class="art-fill">
    <ArtSplitView aside-width="248px">
      <template #aside>
        <ArtTreePanel title="字典分类" :data="treeData" :current-key="currentId" @node-click="onNodeClick">
          <template #header>
            <el-tooltip content="新增顶级分类" placement="top">
              <el-button v-auth="'cccms:dict:category_save'" text circle :icon="Plus" @click="openCategoryCreate(0)" />
            </el-tooltip>
            <!-- 只显示本模块的分类，避免看到附件模块的分类 -->
            <RecycleToggle :active="categoryRecycle" label="字典分类" @toggle="toggleCategoryRecycle" />
          </template>

          <template #node="{ data }">
            <span class="cat-node">
              <span class="cat-node-label">{{ data.name }}</span>
              <!-- 虚拟节点（全部 / 未分类）不允许改 -->
              <span v-if="data.id > 0" class="cat-node-actions">
                <!-- 分类回收站：节点操作换成还原 / 彻底删除 -->
                <template v-if="categoryRecycle">
                  <el-icon title="还原" @click.stop="onCategoryRestore(data)"><RefreshLeft /></el-icon>
                  <el-icon title="彻底删除" @click.stop="onCategoryForceDelete(data)"><Delete /></el-icon>
                </template>
                <template v-else>
                  <el-icon title="新增子分类" @click.stop="openCategoryCreate(data.id)"><Plus /></el-icon>
                  <el-icon title="重命名 / 调整" @click.stop="openCategoryEdit(data)"><Edit /></el-icon>
                  <el-icon title="删除" @click.stop="onCategoryDelete(data)"><Delete /></el-icon>
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
        v-model:page="page"
        v-model:limit="limit"
        @refresh="load"
        @search="search"
        @reset="reset"
        @page-change="onPageChange"
        @size-change="onLimitChange"
        @restore="onRestore"
        @force-delete="onForceDelete"
      >
        <template #search>
          <el-form-item label="字典名称">
            <el-input v-model="query.name" placeholder="请输入" clearable style="width: 180px" />
          </el-form-item>
        </template>

        <template #toolbar>
          <el-button v-auth="'cccms:dict:save'" type="primary" :icon="Plus" @click="openTypeCreate">
            新增字典类型
          </el-button>
          <el-tag v-if="currentId" type="info" closable @close="clearNode"> 仅看：{{ currentNodeName }} </el-tag>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" label="字典类型" @toggle="toggle" />
        </template>

        <template #action="{ row }">
          <el-button v-auth="'cccms:dict:data'" link type="primary" @click="openData(row)"> 字典数据 </el-button>
          <el-button v-auth="'cccms:dict:update'" link type="primary" @click="openTypeEdit(row)">编辑</el-button>
          <el-popconfirm title="删除类型会同时删除其数据，确定？" @confirm="onTypeDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:dict:delete'" link type="danger">删除</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <!-- 分类表单 -->
    <el-dialog
      v-model="categoryVisible"
      :title="categoryForm.id ? '编辑分类' : '新增分类'"
      width="460px"
      :close-on-click-modal="false"
    >
      <el-form ref="categoryFormRef" :model="categoryForm" :rules="categoryRules" label-width="92px">
        <el-form-item label="上级分类" prop="parent_id">
          <el-tree-select
            v-model="categoryForm.parent_id"
            :data="categoryOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="categoryForm.name" placeholder="如 系统字典" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="categoryForm.sort" :min="0" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="categoryForm.remark" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="categoryVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitCategory">确定</el-button>
      </template>
    </el-dialog>

    <!-- 字典类型表单 -->
    <el-dialog
      v-model="typeVisible"
      :title="typeForm.id ? '编辑字典类型' : '新增字典类型'"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="typeFormRef" :model="typeForm" :rules="typeRules" label-width="92px">
        <el-form-item label="所属分类" prop="category_id">
          <el-tree-select
            v-model="typeForm.category_id"
            :data="categorySelectOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="字典名称" prop="name">
          <el-input v-model="typeForm.name" placeholder="如 用户性别" />
        </el-form-item>
        <el-form-item label="字典类型" prop="type">
          <el-input v-model="typeForm.type" placeholder="如 user_gender" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="typeForm.remark" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="typeVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitType">确定</el-button>
      </template>
    </el-dialog>

    <!-- 字典数据抽屉 -->
    <el-drawer v-model="dataVisible" :title="`字典数据 - ${currentType.name}`" size="680px">
      <div class="drawer-toolbar">
        <el-button
          v-if="!dataRecycle"
          v-auth="'cccms:dict:save_data'"
          type="primary"
          :icon="Plus"
          @click="openDataCreate"
        >
          新增数据
        </el-button>
        <RecycleToggle :active="dataRecycle" label="字典数据" @toggle="toggleDataRecycle" />
      </div>
      <el-table v-loading="dataLoading" :data="dataList" row-key="id" stripe border max-height="460">
        <el-table-column prop="label" label="显示名" min-width="140" />
        <el-table-column prop="value" label="值" min-width="120" />
        <el-table-column prop="sort" label="排序" width="80" align="center" />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <template v-if="dataRecycle">
              <el-button v-auth="'cccms:recycle:restore'" link type="primary" @click="onDataRestore(row)">
                还原
              </el-button>
              <el-button v-auth="'cccms:recycle:delete'" link type="danger" @click="onDataForceDelete(row)">
                彻底删除
              </el-button>
            </template>
            <template v-else>
              <el-button v-auth="'cccms:dict:update_data'" link type="primary" @click="openDataEdit(row)">
                编辑
              </el-button>
              <el-popconfirm title="确定删除？" @confirm="onDataDelete(row.id)">
                <template #reference>
                  <el-button v-auth="'cccms:dict:delete_data'" link type="danger">删除</el-button>
                </template>
              </el-popconfirm>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <el-dialog v-model="dataFormVisible" :title="dataForm.id ? '编辑数据' : '新增数据'" width="440px" append-to-body>
        <el-form ref="dataFormRef" :model="dataForm" :rules="dataRules" label-width="80px">
          <el-form-item label="显示名" prop="label">
            <el-input v-model="dataForm.label" />
          </el-form-item>
          <el-form-item label="值" prop="value">
            <el-input v-model="dataForm.value" />
          </el-form-item>
          <el-form-item label="排序" prop="sort">
            <el-input-number v-model="dataForm.sort" :min="0" />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="dataFormVisible = false">取消</el-button>
          <el-button type="primary" :loading="saving" @click="submitData">确定</el-button>
        </template>
      </el-dialog>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:dict' })

import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { Delete, Edit, Plus, RefreshLeft } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { useTable } from '@/composables/useTable'
import { useRecycle } from '@/composables/useRecycle'
import { categoryDelete, categorySave, categoryTree, categoryUpdate, type CategoryNode } from '@/api/category'
import {
  dictDataDelete,
  dictDataList,
  dictDataSave,
  dictDataUpdate,
  dictTypeDelete,
  dictTypeList,
  dictTypeSave,
  dictTypeUpdate,
} from '@/api/dict'
import type { ArtTableColumn } from '@/types/table'

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

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'name', label: '字典名称', minWidth: 150 },
  { prop: 'type', label: '字典类型', minWidth: 150 },
  { prop: 'category_name', label: '所属分类', width: 140 },
  { prop: 'remark', label: '备注', minWidth: 160 },
  { prop: 'action', label: '操作', width: 230, fixed: 'right', slot: 'action', lockVisible: true },
]

/* ---- 左侧分类树 ---- */
const saving = ref(false)
const currentId = ref(0)
const categories = ref<CategoryNode[]>([])

const treeData = computed(() =>
  // 回收站视图里只列已删分类，不掺「全部 / 未分类」这两个虚拟节点
  categoryRecycle.value
    ? categories.value
    : [{ id: ALL_ID, name: '全部' }, { id: NONE_ID, name: '未分类' }, ...categories.value],
)

const currentNodeName = computed(() => {
  if (currentId.value === ALL_ID) {
    return '全部'
  }
  if (currentId.value === NONE_ID) {
    return '未分类'
  }
  return findCategory(categories.value, currentId.value)?.name ?? ''
})

/** 上级分类选择器：顶层补一个「顶级分类」 */
const categoryOptions = computed(() => [{ id: 0, name: '顶级分类', children: categories.value }])
/** 类型归属选择器：顶层补一个「未分类」 */
const categorySelectOptions = computed(() => [{ id: 0, name: '未分类', children: categories.value }])

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
const categoryRules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

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
    ElMessage.success('保存成功')
    categoryVisible.value = false
    await refresh()
  } finally {
    saving.value = false
  }
}

async function onCategoryDelete(record: CategoryNode): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除分类「${record.name}」？`, '删除分类', { type: 'warning' })
  } catch {
    return
  }
  await categoryDelete(MODULE, record.id)
  ElMessage.success('删除成功')
  await refresh()
}

/* ---- 字典类型 ---- */
const typeFormRef = ref<FormInstance>()
const typeVisible = ref(false)
const emptyTypeForm = { id: 0, category_id: 0, name: '', type: '', remark: '' }
const typeForm = reactive<Record<string, any>>({ ...emptyTypeForm })
const typeRules: FormRules = {
  name: [{ required: true, message: '请输入字典名称', trigger: 'blur' }],
  type: [{ required: true, message: '请输入字典类型', trigger: 'blur' }],
}

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
    ElMessage.success('保存成功')
    typeVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onTypeDelete(id: number): Promise<void> {
  await dictTypeDelete(id)
  ElMessage.success('删除成功')
  load()
}

/* ---- 字典数据 ---- */
const dataVisible = ref(false)
const dataLoading = ref(false)
const dataList = ref<Row[]>([])
const currentType = reactive<{ id: number; name: string }>({ id: 0, name: '' })

async function openData(record: Row): Promise<void> {
  currentType.id = record.id
  currentType.name = record.name ?? ''
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
const dataRules: FormRules = {
  label: [{ required: true, message: '请输入显示名', trigger: 'blur' }],
  value: [{ required: true, message: '请输入值', trigger: 'blur' }],
}

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
    ElMessage.success('保存成功')
    dataFormVisible.value = false
    await loadData()
  } finally {
    saving.value = false
  }
}

async function onDataDelete(id: number): Promise<void> {
  await dictDataDelete(id)
  ElMessage.success('删除成功')
  await loadData()
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
