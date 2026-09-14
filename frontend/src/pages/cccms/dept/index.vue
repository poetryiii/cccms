<template>
  <div class="art-fill">
    <ArtSplitView aside-width="240px">
      <template #aside>
        <ArtTreePanel
          title="组织架构"
          :data="treeData"
          :current-key="currentId"
          @node-click="onNodeClick"
        />
      </template>

      <ArtTable
        :columns="columns"
        :data="tableData"
        :loading="loading"
        :pagination="false"
        :recycle="recycle"
        tree
        @refresh="load"
        @restore="onRestore"
        @force-delete="onForceDelete"
      >
        <template #toolbar>
          <el-button v-auth="'cccms:dept:save'" type="primary" :icon="Plus" @click="openCreate()">
            新增
          </el-button>
          <el-tag v-if="currentId" type="info" closable @close="currentId = 0">
            仅看：{{ currentNodeName }}
          </el-tag>
          <span v-else class="toolbar-tip">支持树形层级：点「新增子部门」快速挂载下级</span>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" label="部门" @toggle="toggle" />
        </template>

        <template #status="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
            {{ row.status === 1 ? '启用' : '禁用' }}
          </el-tag>
        </template>

        <template #action="{ row }">
          <el-button v-auth="'cccms:dept:save'" link type="primary" @click="openCreate(row.id)">
            新增子部门
          </el-button>
          <el-button v-auth="'cccms:dept:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-popconfirm title="确定删除该部门？" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:dept:delete'" link type="danger">删除</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑部门' : '新增部门'"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item label="上级部门" prop="parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            placeholder="顶级"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="部门名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入部门名称" />
        </el-form-item>
        <el-form-item label="负责人" prop="leader">
          <el-input v-model="form.leader" placeholder="请输入负责人" />
        </el-form-item>
        <el-form-item label="联系电话" prop="phone">
          <el-input v-model="form.phone" placeholder="请输入联系电话" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" placeholder="请输入邮箱" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:dept' })

import { computed, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { deptDelete, deptSave, deptTree, deptUpdate } from '@/api/dept'
import type { ArtTableColumn } from '@/types/table'

interface Row {
  id: number
  name?: string
  leader?: string
  phone?: string
  email?: string
  sort?: number
  status?: number
  parent_id?: number
  children?: Row[]
}

const columns: ArtTableColumn[] = [
  { prop: 'name', label: '部门名称', minWidth: 200 },
  { prop: 'leader', label: '负责人', width: 140 },
  { prop: 'phone', label: '联系电话', width: 160 },
  { prop: 'email', label: '邮箱', minWidth: 180, defaultHidden: true },
  { prop: 'sort', label: '排序', width: 80, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', slot: 'status' },
  { prop: 'action', label: '操作', width: 230, fixed: 'right', slot: 'action', lockVisible: true },
]

const loading = ref(false)
const list = ref<Row[]>([])
const parentOptions = ref<Row[]>([])

/* ---- 左侧树：只做定位与筛选，不改动右侧的增删改查 ---- */
/** 当前选中的部门 id，0 = 全部 */
const currentId = ref(0)

/** 左侧树：顶部补一个「全部部门」虚拟节点 */
const treeData = computed<Row[]>(() => [{ id: 0, name: '全部部门' }, ...list.value])

function findNode(nodes: Row[], id: number): Row | null {
  for (const node of nodes) {
    if (node.id === id) {
      return node
    }
    if (node.children?.length) {
      const hit = findNode(node.children, id)
      if (hit) {
        return hit
      }
    }
  }
  return null
}

const currentNodeName = computed(() => (currentId.value ? findNode(list.value, currentId.value)?.name ?? '' : ''))

/** 右侧表格：选中节点时只显示该节点及其子树 */
const tableData = computed<Row[]>(() => {
  // 回收站里是平铺的已删部门，不再按左侧选的部门过滤，否则会看不到一部分
  if (recycle.value || !currentId.value) {
    return list.value
  }
  const node = findNode(list.value, currentId.value)
  return node ? [node] : []
})

function onNodeClick(data: Record<string, any>): void {
  currentId.value = Number(data.id ?? 0)
}

// 回收站开关（load() 在本文件末尾首次执行，那时它已初始化）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('dept', {
  reload: () => load(),
})

async function load(): Promise<void> {
  loading.value = true
  try {
    // trashed=true → 后端返回平铺的已删部门（父节点可能还活着，拼不出完整树）
    list.value = (await deptTree(recycle.value)) as unknown as Row[]
    parentOptions.value = [{ id: 0, name: '顶级', children: list.value }]

    // 选中的部门被删掉后，回落到「全部」，避免右侧一直空白
    if (currentId.value && !findNode(list.value, currentId.value)) {
      currentId.value = 0
    }
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
  name: '',
  leader: '',
  phone: '',
  email: '',
  sort: 0,
  status: 1,
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules: FormRules = {
  name: [{ required: true, message: '请输入部门名称', trigger: 'blur' }],
}

/** 不传 parentId 时默认挂到左侧选中的部门下 */
function openCreate(parentId?: number): void {
  Object.assign(form, emptyForm, { parent_id: parentId ?? currentId.value })
  formVisible.value = true
}

function openEdit(record: Row): void {
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
      await deptUpdate({ ...form })
    } else {
      await deptSave({ ...form })
    }
    ElMessage.success('保存成功')
    formVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await deptDelete(id)
  ElMessage.success('删除成功')
  await load()
}

load()
</script>

<style scoped>
.toolbar-tip {
  font-size: 12px;
  color: var(--art-muted);
}
</style>
