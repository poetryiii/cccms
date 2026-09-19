<template>
  <div class="art-fill">
    <ArtSplitView aside-width="240px">
      <template #aside>
        <ArtTreePanel
          :title="t('role.treeTitle')"
          :data="treeData"
          :current-key="currentId"
          @node-click="onNodeClick"
        />
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
          <el-form-item :label="t('role.name')">
            <el-input v-model="query.name" :placeholder="t('role.pleaseInput')" clearable style="width: 180px" />
          </el-form-item>
        </template>

        <template #toolbar>
          <el-button v-auth="'cccms:role:save'" type="primary" :icon="Plus" @click="openCreate">
            {{ t('common.create') }}
          </el-button>
          <el-tag v-if="currentId" type="info" closable @close="clearNode">
            {{ t('role.onlyView', { name: currentNodeName }) }}
          </el-tag>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" :label="t('role.entityLabel')" @toggle="toggle" />
        </template>

        <template #parent="{ row }">
          {{ row.parent_id ? roleName(row.parent_id) : t('role.topRole') }}
        </template>

        <template #scope="{ row }">
          <el-tag effect="plain">{{ scopeText(row.data_scope) }}</el-tag>
        </template>

        <template #status="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
            {{ row.status === 1 ? t('role.enabled') : t('role.disabled') }}
          </el-tag>
        </template>

        <template #action="{ row }">
          <el-button v-auth="'cccms:role:update'" link type="primary" @click="openEdit(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-auth="'cccms:role:copy'" link type="warning" @click="openCopy(row)">
            {{ t('role.copy') }}
          </el-button>
          <el-popconfirm :title="t('role.confirmDelete')" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:role:delete'" link type="danger">{{ t('common.delete') }}</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('role.editRole') : t('role.createRole')"
      width="640px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
        <el-form-item :label="t('role.name')" prop="name">
          <el-input v-model="form.name" :placeholder="t('role.nameRequired')" />
        </el-form-item>
        <el-form-item :label="t('role.code')" prop="code">
          <el-input
            v-model="form.code"
            :disabled="form.code === 'super_admin'"
            :placeholder="t('role.codePlaceholder')"
          />
        </el-form-item>
        <el-form-item :label="t('role.parent')" prop="parent_id">
          <el-select v-model="form.parent_id" :placeholder="t('role.topRole')" style="width: 100%">
            <el-option :label="t('role.noneTop')" :value="0" />
            <el-option
              v-for="r in parentOptions"
              :key="r.id"
              :label="r.name"
              :value="r.id"
              :disabled="r.id === form.id"
            />
          </el-select>
          <div class="form-tip">{{ t('role.parentTip') }}</div>
        </el-form-item>
        <el-form-item :label="t('role.dataScope')" prop="data_scope">
          <el-select v-model="form.data_scope" style="width: 100%">
            <el-option :label="t('role.scopeAll')" :value="1" />
            <el-option :label="t('role.scopeDeptAndBelow')" :value="2" />
            <el-option :label="t('role.scopeDept')" :value="3" />
            <el-option :label="t('role.scopeSelf')" :value="4" />
            <el-option :label="t('role.scopeCustom')" :value="5" />
          </el-select>
          <div class="form-tip">
            {{ t('role.scopeTipPrefix') }}<b>{{ t('role.scopeTipAnd') }}</b
            >{{ t('role.scopeTipMiddle') }}<b>{{ t('role.scopeTipAnd2') }}</b
            >{{ t('role.scopeTipSuffix') }}
          </div>
        </el-form-item>
        <el-form-item :label="t('role.status')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('role.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('role.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('role.permNodes')">
          <div class="node-panel">
            <div class="node-panel-tools">
              <el-button size="small" @click="toggleExpandAll">
                {{ expandAll ? t('role.collapseAll') : t('role.expandAll') }}
              </el-button>
              <el-button size="small" @click="checkAllNodes">{{ t('role.selectAll') }}</el-button>
              <el-button size="small" @click="clearAllNodes">{{ t('role.clear') }}</el-button>
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
        <el-button @click="formVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 复制角色：只复制角色本体（含档位）与自身节点，不复制子角色 -->
    <el-dialog v-model="copyVisible" :title="t('role.copyRole')" width="520px" :close-on-click-modal="false">
      <el-form ref="copyRef" :model="copyForm" :rules="copyRules" label-width="110px">
        <el-form-item :label="t('role.sourceRole')">
          <el-input :model-value="copySource?.name" disabled />
        </el-form-item>
        <el-form-item :label="t('role.newRoleName')" prop="name">
          <el-input v-model="copyForm.name" :placeholder="t('role.newRoleNameRequired')" />
        </el-form-item>
        <el-form-item :label="t('role.newRoleCode')" prop="code">
          <el-input v-model="copyForm.code" :placeholder="t('role.newRoleCodePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('role.parent')">
          <el-select v-model="copyForm.parent_id" :placeholder="t('role.topRole')" style="width: 100%">
            <el-option :label="t('role.noneTop')" :value="0" />
            <el-option v-for="r in parentOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
          <div class="form-tip">{{ t('role.copyParentTip') }}</div>
        </el-form-item>
        <el-form-item :label="t('role.saveAsTemplate')">
          <el-switch v-model="copyAsTemplate" />
          <span class="form-tip form-tip-inline">{{ t('role.templateTip') }}</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="copyVisible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="copying" @click="submitCopy">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:role' })

import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElTree, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { useTable } from '@/composables/useTable'
import { roleCopy, roleDelete, roleList, roleRead, roleSave, roleTree, roleUpdate } from '@/api/role'
import { menuTree } from '@/api/menu'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

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

const scopeTextMap = computed<Record<number, string>>(() => ({
  1: t('role.scopeAll'),
  2: t('role.scopeDeptAndBelow'),
  3: t('role.scopeDept'),
  4: t('role.scopeSelf'),
  5: t('role.scopeCustomShort'),
}))

const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'id', label: 'ID', width: 76 },
  { prop: 'name', label: t('role.name'), minWidth: 150 },
  { prop: 'code', label: t('role.code'), minWidth: 150 },
  { prop: 'parent_id', label: t('role.parent'), width: 130, slot: 'parent' },
  { prop: 'data_scope', label: t('role.dataScope'), width: 140, align: 'center', slot: 'scope' },
  { prop: 'status', label: t('role.status'), width: 90, align: 'center', slot: 'status' },
  { prop: 'action', label: t('table.action'), width: 170, fixed: 'right', slot: 'action', lockVisible: true },
])

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
const treeData = computed<Row[]>(() => [{ id: 0, name: t('role.allRoles') }, ...roleTreeData.value])
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

const scopeText = (value?: number): string => scopeTextMap.value[value ?? 0] ?? '-'
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
  // 默认「仅本人」：档位不继承父角色、按「取最宽松」生效，漏配必须兜底到最窄范围
  data_scope: 4,
  status: 1,
  nodes: [] as string[],
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('role.nameRequired'), trigger: 'blur' }],
  code: [{ required: true, message: t('role.codeRequired'), trigger: 'blur' }],
}))

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
    ElMessage.success(t('role.saveSuccess'))
    formVisible.value = false
    load()
    await loadOptions()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await roleDelete(id)
  ElMessage.success(t('role.deleteSuccess'))
  load()
  await loadOptions()
}

/* ---- 复制角色 ---- */
const copyRef = ref<FormInstance>()
const copyVisible = ref(false)
const copying = ref(false)
const copyAsTemplate = ref(false)
const copySource = ref<Row | null>(null)
const copyForm = reactive({ id: 0, name: '', code: '', parent_id: 0 })

const copyRules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('role.newRoleNameRequired'), trigger: 'blur' }],
  code: [{ required: true, message: t('role.newRoleCodeRequired'), trigger: 'blur' }],
}))

function openCopy(row: Row): void {
  copySource.value = row
  // 与后端 suggestCode() 的派生命名保持一致，用户可改
  Object.assign(copyForm, {
    id: row.id,
    name: t('role.copyNameSuffix', { name: row.name ?? '' }),
    code: `${row.code ?? ''}_copy`,
    parent_id: (row.parent_id as number) ?? 0,
  })
  copyAsTemplate.value = false
  copyVisible.value = true
}

async function submitCopy(): Promise<void> {
  const valid = await copyRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  copying.value = true
  try {
    await roleCopy({
      ...copyForm,
      // 不勾「模板」时不传 status，由后端沿用源角色的状态
      ...(copyAsTemplate.value ? { status: 0 } : {}),
    })
    ElMessage.success(copyAsTemplate.value ? t('role.copyTemplateSuccess') : t('role.copySuccess'))
    copyVisible.value = false
    load()
    await loadOptions()
  } finally {
    copying.value = false
  }
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

.form-tip-inline {
  margin: 0 0 0 10px;
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
