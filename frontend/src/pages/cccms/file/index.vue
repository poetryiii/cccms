<template>
  <div class="art-fill">
    <ArtSplitView aside-width="248px">
      <template #aside>
        <ArtTreePanel
          title="附件分类"
          :data="treeData"
          :current-key="currentId"
          @node-click="onNodeClick"
        >
          <template #header>
            <el-tooltip content="新增顶级分类" placement="top">
              <el-button
                v-auth="'cccms:file:category_save'"
                text
                circle
                :icon="Plus"
                @click="openCategoryCreate(0)"
              />
            </el-tooltip>
            <!-- 只显示附件模块的分类，避免看到字典模块的分类 -->
            <RecycleToggle
              :active="categoryRecycle"
              label="附件分类"
              @toggle="toggleCategoryRecycle"
            />
          </template>

          <template #node="{ data }">
            <span class="cat-node">
              <span class="cat-node-label">{{ data.name }}</span>
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
        selection
        v-model:page="page"
        v-model:limit="limit"
        @refresh="load"
        @search="search"
        @reset="reset"
        @page-change="onPageChange"
        @size-change="onLimitChange"
        @selection-change="onSelectionChange"
        @restore="onRestore"
        @force-delete="onForceDelete"
      >
        <template #search>
          <el-form-item label="文件名">
            <el-input v-model="query.original_name" placeholder="请输入" clearable style="width: 190px" />
          </el-form-item>
          <el-form-item label="扩展名">
            <el-input v-model="query.ext" placeholder="如 png" clearable style="width: 130px" />
          </el-form-item>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" label="附件" @toggle="toggle" />
        </template>

        <template #toolbar>
          <el-upload
            v-auth="'cccms:file:upload'"
            :action="uploadUrl"
            :headers="uploadHeaders"
            :data="uploadData"
            :show-file-list="false"
            :before-upload="onBeforeUpload"
            :on-success="onUploadSuccess"
            :on-error="onUploadError"
          >
            <el-button type="primary" :icon="Upload" :loading="uploading">上传附件</el-button>
          </el-upload>

          <el-button
            v-auth="'cccms:file:move'"
            :icon="FolderChecked"
            :disabled="selection.length === 0"
            @click="openMove"
          >
            移动到分类{{ selection.length ? `（${selection.length}）` : '' }}
          </el-button>

          <el-tag v-if="currentId" type="info" closable @close="clearNode">
            仅看：{{ currentNodeName }}
          </el-tag>
          <span v-else class="toolbar-tip">单文件上限 10MB；相同内容自动去重</span>
        </template>

        <!-- is_image 由后台 upload.image_ext 配置推导 -->
        <template #preview="{ row }">
          <el-image
            v-if="row.is_image"
            class="file-thumb"
            :src="row.url"
            :preview-src-list="[row.url]"
            preview-teleported
            fit="cover"
          />
          <el-icon v-else :size="18" class="file-thumb-icon"><Document /></el-icon>
        </template>

        <template #ext="{ row }">
          <el-tag effect="plain" size="small">{{ row.ext }}</el-tag>
        </template>

        <template #size="{ row }">
          {{ formatSize(row.size) }}
        </template>

        <template #action="{ row }">
          <el-button link type="primary" @click="openUrl(row.url)">查看</el-button>
          <el-popconfirm title="确定删除该附件？" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:file:delete'" link type="danger">删除</el-button>
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
          <el-input v-model="categoryForm.name" placeholder="如 合同附件" />
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

    <!-- 移动到分类 -->
    <el-dialog v-model="moveVisible" title="移动到分类" width="420px">
      <el-form label-width="80px">
        <el-form-item label="目标分类">
          <el-tree-select
            v-model="moveTarget"
            :data="moveOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="moveVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitMove">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:file' })

import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules, type UploadRawFile } from 'element-plus'
import { Delete, Document, Edit, FolderChecked, Plus, RefreshLeft, Upload } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { useTable } from '@/composables/useTable'
import { useRecycle } from '@/composables/useRecycle'
import {
  categoryDelete,
  categorySave,
  categoryTree,
  categoryUpdate,
  type CategoryNode,
} from '@/api/category'
import { FILE_UPLOAD_URL, fileDelete, fileList, fileMove, type FileRow } from '@/api/file'
import { getToken } from '@/utils/auth'
import { UPLOAD_PROGRESS, progressDone, progressStart } from '@/utils/progress'
import type { ArtTableColumn } from '@/types/table'

const MODULE = 'file' as const
/** 虚拟节点：全部 / 未分类（后端约定 category_id：0=全部，-1=未分类） */
const ALL_ID = 0
const NONE_ID = -1

interface Query {
  original_name: string
  ext: string
}

const MAX_SIZE = 10 * 1024 * 1024

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'preview', label: '预览', width: 76, align: 'center', slot: 'preview' },
  { prop: 'original_name', label: '文件名', minWidth: 200 },
  { prop: 'category_name', label: '所属分类', width: 140 },
  { prop: 'ext', label: '类型', width: 100, align: 'center', slot: 'ext' },
  { prop: 'size', label: '大小', width: 110, align: 'right', slot: 'size' },
  { prop: 'create_time', label: '上传时间', width: 170 },
  { prop: 'action', label: '操作', width: 130, fixed: 'right', slot: 'action', lockVisible: true },
]

/* ---- 左侧分类树 ---- */
const saving = ref(false)
const currentId = ref(ALL_ID)
const categories = ref<CategoryNode[]>([])

const treeData = computed(() =>
  // 回收站视图里只列已删分类，不掺「全部 / 未分类」这两个虚拟节点
  categoryRecycle.value
    ? categories.value
    : [
        { id: ALL_ID, name: '全部' },
        { id: NONE_ID, name: '未分类' },
        ...categories.value,
      ],
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

const categoryOptions = computed(() => [{ id: 0, name: '顶级分类', children: categories.value }])
/** 移动目标：允许移出分类 */
const moveOptions = computed(() => [{ id: 0, name: '未分类', children: categories.value }])

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

/* ---- 回收站：本页两个入口（附件 / 附件分类）各一个开关 ---- */
// 声明在 useTable 之前：列表闭包在 setup 阶段就会执行一次
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('file', {
  reload: () => search(),
  ids: () => selection.value.map((row) => row.id),
  clear: () => {
    selection.value = []
  },
})
const {
  recycle: categoryRecycle,
  toggle: toggleCategoryRecycle,
  onRestore: onCategoryRestore,
  onForceDelete: onCategoryForceDelete,
} = useRecycle('category', { reload: () => loadCategories() })

const {
  list, loading, total, page, limit, query, selection,
  load, search, reset, onPageChange, onLimitChange, onSelectionChange,
} = useTable<FileRow, Query>({
  api: (params) => fileList({ ...params, category_id: currentId.value, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { original_name: '', ext: '' },
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

/* ---- 上传 ---- */
const uploading = ref(false)
const uploadUrl = FILE_UPLOAD_URL
const uploadHeaders = computed(() => ({ Authorization: `Bearer ${getToken()}` }))
/** 上传直接落到左侧选中的分类（全部/未分类 → 0） */
const uploadData = computed(() => ({ category_id: currentId.value > 0 ? currentId.value : 0 }))

function onBeforeUpload(file: UploadRawFile): boolean {
  if (file.size > MAX_SIZE) {
    ElMessage.warning('单个文件不能超过 10MB')
    return false
  }
  uploading.value = true
  // el-upload 走原生 XHR，不经过 axios 实例，所以进度条要在这里单独登记；
  // 必须放在体积校验通过之后，否则 return false 不会触发 on-success / on-error，进度条会卡住
  progressStart(UPLOAD_PROGRESS)
  return true
}

function onUploadSuccess(response: unknown): void {
  uploading.value = false
  progressDone(UPLOAD_PROGRESS)
  const body = typeof response === 'string' ? safeParse(response) : (response as { code?: number; message?: string })
  if (body && body.code === 0) {
    ElMessage.success('上传成功')
    search()
  } else {
    ElMessage.error(body?.message || '上传失败')
  }
}

function onUploadError(): void {
  uploading.value = false
  progressDone(UPLOAD_PROGRESS)
  ElMessage.error('上传失败')
}

function safeParse(text: string): { code?: number; message?: string } | null {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

/* ---- 移动到分类 ---- */
const moveVisible = ref(false)
const moveTarget = ref(0)

function openMove(): void {
  moveTarget.value = currentId.value > 0 ? currentId.value : 0
  moveVisible.value = true
}

async function submitMove(): Promise<void> {
  const ids = selection.value.map((row) => row.id)
  if (!ids.length) {
    return
  }
  saving.value = true
  try {
    await fileMove(ids, moveTarget.value)
    ElMessage.success(`已移动 ${ids.length} 个附件`)
    moveVisible.value = false
    // 表格未开 reserve-selection，刷新后勾选会自动清空
    load()
  } finally {
    saving.value = false
  }
}

/* ---- 其它 ---- */
function openUrl(url: string): void {
  window.open(url, '_blank', 'noopener')
}

async function onDelete(id: number): Promise<void> {
  await fileDelete(id)
  ElMessage.success('删除成功')
  load()
}

function formatSize(size: number): string {
  if (!size) {
    return '0 B'
  }
  if (size < 1024) {
    return `${size} B`
  }
  if (size < 1024 * 1024) {
    return `${(size / 1024).toFixed(1)} KB`
  }
  return `${(size / 1048576).toFixed(2)} MB`
}

loadCategories()
</script>

<style scoped>
.toolbar-tip {
  font-size: 12px;
  color: var(--art-muted);
}

.file-thumb {
  width: 34px;
  height: 34px;
  border-radius: 6px;
}

.file-thumb-icon {
  color: var(--art-muted);
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
