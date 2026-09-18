<template>
  <div class="art-fill">
    <ArtSplitView aside-width="240px">
      <template #aside>
        <ArtTreePanel title="角色层级" :data="treeData" :current-key="currentId" @node-click="onNodeClick" />
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
          <el-form-item label="角色名称">
            <el-input v-model="query.name" placeholder="请输入" clearable style="width: 180px" />
          </el-form-item>
        </template>

        <template #toolbar>
          <el-button v-auth="'cccms:role:save'" type="primary" :icon="Plus" @click="openCreate"> 新增 </el-button>
          <el-tag v-if="currentId" type="info" closable @close="clearNode">
            仅看：{{ currentNodeName }} 及其下级
          </el-tag>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" label="角色" @toggle="toggle" />
        </template>

        <template #parent="{ row }">
          {{ row.parent_id ? roleName(row.parent_id) : '顶级角色' }}
        </template>

        <template #scope="{ row }">
          <el-tag effect="plain">{{ scopeText(row.data_scope) }}</el-tag>
        </template>

        <template #status="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
            {{ row.status === 1 ? '启用' : '禁用' }}
          </el-tag>
        </template>

        <template #action="{ row }">
          <el-button v-auth="'cccms:role:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-popconfirm title="确定删除该角色？" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:role:delete'" link type="danger">删除</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑角色' : '新增角色'"
      width="640px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
        <el-form-item label="角色名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入角色名称" />
        </el-form-item>
        <el-form-item label="角色标识" prop="code">
          <el-input
            v-model="form.code"
            :disabled="form.code === 'super_admin'"
            placeholder="如 sale_manager（超管角色不可改）"
          />
        </el-form-item>
        <el-form-item label="父角色" prop="parent_id">
          <el-select v-model="form.parent_id" placeholder="顶级角色" style="width: 100%">
            <el-option label="无（顶级）" :value="0" />
            <el-option
              v-for="r in parentOptions"
              :key="r.id"
              :label="r.name"
              :value="r.id"
              :disabled="r.id === form.id"
            />
          </el-select>
          <div class="form-tip">子角色自动继承父角色的权限节点，此处只需勾选本角色独有节点。</div>
        </el-form-item>
        <el-form-item label="数据范围" prop="data_scope">
          <el-select v-model="form.data_scope" style="width: 100%">
            <el-option label="全部数据" :value="1" />
            <el-option label="本部门及以下" :value="2" />
            <el-option label="本部门" :value="3" />
            <el-option label="仅本人" :value="4" />
            <el-option label="自定义规则" :value="5" />
          </el-select>
          <div class="form-tip">
            这里是「基线」。数据权限页里绑定到本角色的行级规则会<b>叠加（AND）</b>在基线之上：
            选「本部门及以下」再配自定义规则 = 只看本部门及以下<b>且</b>满足规则的数据。
            「全部数据」档下自定义行级规则不生效；「自定义规则」档没有基线，一条规则都没命中就看不到任何数据。
          </div>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="权限节点">
          <div class="node-panel">
            <div class="node-panel-tools">
              <el-button size="small" @click="toggleExpandAll">
                {{ expandAll ? '全部折叠' : '全部展开' }}
              </el-button>
              <el-button size="small" @click="checkAllNodes">全选</el-button>
              <el-button size="small" @click="clearAllNodes">清空</el-button>
            </div>
            <el-tree
              :key="treeKey"
              ref="treeRef"
              class="node-tree"
              :data="menuTreeData"
              :props="{ label: 'title', children: 'children' }"
              node-key="node"
              show-checkbox
              :default-expand-all="expandAll"
            />
          </div>
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
defineOptions({ name: 'cccms:role' })

import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElTree, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { useTable } from '@/composables/useTable'
import { roleDelete, roleList, roleRead, roleSave, roleTree, roleUpdate } from '@/api/role'
import { menuTree } from '@/api/menu'
import type { ArtTableColumn } from '@/types/table'

interface Row {
  id: number
  name?: string
  code?: string
  parent_id?: number
  data_scope?: number
  status?: number
  [key: string]: unknown
}

interface Query {
  name: string
}

const scopeTextMap: Record<number, string> = {
  1: '全部数据',
  2: '本部门及以下',
  3: '本部门',
  4: '仅本人',
  5: '自定义',
}

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'name', label: '角色名称', minWidth: 150 },
  { prop: 'code', label: '角色标识', minWidth: 150 },
  { prop: 'parent_id', label: '父角色', width: 130, slot: 'parent' },
  { prop: 'data_scope', label: '数据范围', width: 140, align: 'center', slot: 'scope' },
  { prop: 'status', label: '状态', width: 90, align: 'center', slot: 'status' },
  { prop: 'action', label: '操作', width: 130, fixed: 'right', slot: 'action', lockVisible: true },
]

/* ---- 左侧树：选中节点后仅列出该角色及其下级 ---- */
/** 0 = 全部 */
const currentId = ref(0)

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('role', {
  reload: () => search(),
})

const { list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange } = useTable<
  Row,
  Query
>({
  // node_id 在请求时注入，这样「重置」只清查询条件，不会意外丢掉树上的筛选
  api: (params) => roleList({ ...params, node_id: currentId.value || undefined, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '' },
})

const roleTreeData = ref<Row[]>([])
const treeData = computed<Row[]>(() => [{ id: 0, name: '全部角色' }, ...roleTreeData.value])
const currentNodeName = computed(() =>
  currentId.value ? (flatten(roleTreeData.value).find((r) => r.id === currentId.value)?.name ?? '') : '',
)

function onNodeClick(data: Record<string, any>): void {
  currentId.value = Number(data.id ?? 0)
  search()
}

function clearNode(): void {
  currentId.value = 0
  search()
}

/* ---- 下拉与权限树 ---- */
const parentOptions = ref<Row[]>([])
const menuTreeData = ref<Record<string, unknown>[]>([])
const expandAll = ref(false)
/** 用于强制重挂载 el-tree（它的 default-expand-all 只在初始化生效） */
const treeKey = ref(0)
const treeRef = ref<InstanceType<typeof ElTree>>()

const scopeText = (value?: number): string => scopeTextMap[value ?? 0] ?? '-'
const roleName = (id?: number): string => parentOptions.value.find((r) => r.id === id)?.name ?? '-'

function flatten(tree: Row[]): Row[] {
  const out: Row[] = []
  const walk = (nodes: Row[]) => {
    for (const n of nodes) {
      out.push(n)
      const children = (n as { children?: Row[] }).children
      if (children?.length) {
        walk(children)
      }
    }
  }
  walk(tree)
  return out
}

async function loadOptions(): Promise<void> {
  const [roles, menus] = await Promise.all([roleTree(), menuTree()])
  roleTreeData.value = roles as unknown as Row[]
  parentOptions.value = flatten(roleTreeData.value)
  menuTreeData.value = menus as unknown as Record<string, unknown>[]
}

/* ---- 表单 ---- */
const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  name: '',
  code: '',
  parent_id: 0,
  data_scope: 1,
  status: 1,
  nodes: [] as string[],
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules: FormRules = {
  name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }],
  code: [{ required: true, message: '请输入角色标识', trigger: 'blur' }],
}

/** 新增时默认挂在左侧选中的角色下 */
function openCreate(): void {
  Object.assign(form, emptyForm, { parent_id: currentId.value, nodes: [] })
  formVisible.value = true
  nextTick(() => treeRef.value?.setCheckedKeys([]))
}

async function openEdit(record: Row): Promise<void> {
  Object.assign(form, emptyForm, { nodes: [] })
  const detail = await roleRead(record.id)
  Object.assign(form, detail, { nodes: [] })
  formVisible.value = true
  await nextTick()
  treeRef.value?.setCheckedKeys((detail as { own?: string[] }).own ?? [])
}

/**
 * el-tree 的 default-expand-all 只在初始化时生效，
 * 所以通过 key 重挂载来切换，重挂载前先记住已勾选节点。
 */
function toggleExpandAll(): void {
  const checked = (treeRef.value?.getCheckedKeys() ?? []) as string[]
  expandAll.value = !expandAll.value
  treeKey.value += 1
  nextTick(() => treeRef.value?.setCheckedKeys(checked))
}

function checkAllNodes(): void {
  treeRef.value?.setCheckedKeys(collectNodeKeys(menuTreeData.value))
}

function clearAllNodes(): void {
  treeRef.value?.setCheckedKeys([])
}

function collectNodeKeys(nodes: Record<string, unknown>[]): string[] {
  const out: string[] = []
  const walk = (items: Record<string, unknown>[]) => {
    for (const item of items) {
      if (item.node) {
        out.push(String(item.node))
      }
      const children = item.children as Record<string, unknown>[] | undefined
      if (children?.length) {
        walk(children)
      }
    }
  }
  walk(nodes)
  return out
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  // 显式构造提交体：不要把 roleRead() 回显的 own / inherited 这类只读字段带回去
  const payload = {
    id: form.id,
    name: form.name,
    code: form.code,
    parent_id: form.parent_id,
    data_scope: form.data_scope,
    status: form.status,
    nodes: (treeRef.value?.getCheckedKeys() ?? []) as string[],
  }

  saving.value = true
  try {
    if (payload.id) {
      await roleUpdate(payload)
    } else {
      await roleSave(payload)
    }
    ElMessage.success('保存成功')
    formVisible.value = false
    load()
    await loadOptions()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await roleDelete(id)
  ElMessage.success('删除成功')
  load()
  await loadOptions()
}

onMounted(loadOptions)
</script>

<style scoped>
.form-tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}

.node-panel {
  width: 100%;
  border: 1px solid var(--art-card-border);
  border-radius: calc(var(--art-radius) - 2px);
}

.node-panel-tools {
  display: flex;
  gap: 6px;
  padding: 8px 10px;
  border-bottom: 1px solid var(--art-card-border);
}

.node-tree {
  max-height: 300px;
  padding: 8px 6px;
  overflow-y: auto;
}
</style>
