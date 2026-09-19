<template>
  <div class="art-fill">
    <ArtSplitView aside-width="240px">
      <template #aside>
        <ArtTreePanel
          :title="t('dept.treeTitle')"
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
            {{ t('common.create') }}
          </el-button>
          <el-tag v-if="currentId" type="info" closable @close="currentId = 0">
            {{ t('dept.filteredLabel', { name: currentNodeName }) }}
          </el-tag>
          <span v-else class="toolbar-tip">{{ t('dept.treeTip') }}</span>
        </template>

        <template #toolbar-right>
          <RecycleToggle :active="recycle" :label="t('dept.recycleLabel')" @toggle="toggle" />
        </template>

        <template #status="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
            {{ row.status === 1 ? t('dept.enabled') : t('dept.disabled') }}
          </el-tag>
        </template>

        <template #action="{ row }">
          <el-button v-auth="'cccms:dept:save'" link type="primary" @click="openCreate(row.id)">
            {{ t('dept.addChild') }}
          </el-button>
          <el-button v-auth="'cccms:dept:update'" link type="primary" @click="openEdit(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-popconfirm :title="t('dept.deleteConfirm')" @confirm="onDelete(row.id)">
            <template #reference>
              <el-button v-auth="'cccms:dept:delete'" link type="danger">{{ t('common.delete') }}</el-button>
            </template>
          </el-popconfirm>
        </template>
      </ArtTable>
    </ArtSplitView>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? t('dept.editTitle') : t('dept.createTitle')"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="92px">
        <el-form-item :label="t('dept.parentLabel')" prop="parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentOptions"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            check-strictly
            :placeholder="t('dept.topLevel')"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item :label="t('dept.nameLabel')" prop="name">
          <el-input v-model="form.name" :placeholder="t('dept.namePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dept.leaderLabel')" prop="leader">
          <el-input v-model="form.leader" :placeholder="t('dept.leaderPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dept.phoneLabel')" prop="phone">
          <el-input v-model="form.phone" :placeholder="t('dept.phonePlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dept.emailLabel')" prop="email">
          <el-input v-model="form.email" :placeholder="t('dept.emailPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('dept.sortLabel')" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('dept.statusLabel')" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ t('dept.enabled') }}</el-radio>
            <el-radio :value="0">{{ t('dept.disabled') }}</el-radio>
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
defineOptions({ name: 'cccms:dept' })

import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ArtSplitView from '@/components/core/ArtSplitView.vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import { useTableFilter } from '@/composables/useTable'
import ArtTreePanel from '@/components/core/ArtTreePanel.vue'
import { deptDelete, deptSave, deptTree, deptUpdate } from '@/api/dept'
import type { ArtTableColumn } from '@/types/table'

const { t } = useI18n({ useScope: 'global' })

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

// 表格列文案跟随语言切换，用 computed 包裹
const columns = computed<ArtTableColumn[]>(() => [
  { prop: 'name', label: t('dept.nameLabel'), minWidth: 200, filter: { type: 'text' } },
  { prop: 'leader', label: t('dept.leaderLabel'), width: 140, filter: { type: 'text' } },
  { prop: 'phone', label: t('dept.phoneLabel'), width: 160 },
  { prop: 'email', label: t('dept.emailLabel'), minWidth: 180 },
  { prop: 'sort', label: t('dept.sortLabel'), width: 80, align: 'center' },
  {
    prop: 'status',
    label: t('dept.statusLabel'),
    width: 90,
    align: 'center',
    slot: 'status',
    filter: {
      type: 'enum',
      options: [
        { label: t('dept.enabled'), value: 1 },
        { label: t('dept.disabled'), value: 0 },
      ],
    },
  },
  { prop: 'action', label: t('table.action'), width: 230, fixed: 'right', slot: 'action', lockVisible: true },
])

const loading = ref(false)
const list = ref<Row[]>([])

/** 上级部门下拉：根节点名称跟随语言切换，用 computed 包裹 */
const parentOptions = computed<Row[]>(() => [{ id: 0, name: t('dept.topLevel'), children: list.value }])

/* ---- 左侧树：只做定位与筛选，不改动右侧的增删改查 ---- */
/** 当前选中的部门 id，0 = 全部 */
const currentId = ref(0)

/** 左侧树：顶部补一个「全部部门」虚拟节点 */
const treeData = computed<Row[]>(() => [{ id: 0, name: t('dept.allDepts') }, ...list.value])

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

const currentNodeName = computed(() => (currentId.value ? (findNode(list.value, currentId.value)?.name ?? '') : ''))

/* ---- 关键字过滤（前端过滤，不请求接口） ---- */
interface Query {
  name: string
  leader: string
  /** 列头枚举多选，值形如 `1,0` */
  status: string
}

const { query } = useTableFilter<Query>({ initialQuery: { name: '', leader: '', status: '' } })

/** 枚举多选匹配：空条件放行，否则按逗号串匹配 */
function matchEnum(value: unknown, raw: string): boolean {
  return raw === '' || raw.split(',').includes(String(value))
}

/**
 * 树形过滤：命中节点整棵子树原样保留；未命中但子孙命中的节点保留自身，children 换成过滤结果。
 */
function filterTree(nodes: Row[], match: (node: Row) => boolean): Row[] {
  const out: Row[] = []
  for (const node of nodes) {
    if (match(node)) {
      out.push(node)
      continue
    }
    const children = node.children?.length ? filterTree(node.children, match) : []
    if (children.length) {
      out.push({ ...node, children })
    }
  }
  return out
}

/** 右侧表格：先按左侧选中节点收窄，再按关键字过滤 */
const tableData = computed<Row[]>(() => {
  // 回收站里是平铺的已删部门，不再按左侧选的部门过滤，否则会看不到一部分
  let scoped = list.value
  if (!recycle.value && currentId.value) {
    const node = findNode(list.value, currentId.value)
    scoped = node ? [node] : []
  }

  const name = query.name.trim().toLowerCase()
  const leader = query.leader.trim().toLowerCase()
  const status = query.status
  if (!name && !leader && !status) {
    return scoped
  }
  return filterTree(scoped, (item) => {
    const hitName = !name || (item.name ?? '').toLowerCase().includes(name)
    const hitLeader = !leader || (item.leader ?? '').toLowerCase().includes(leader)
    return hitName && hitLeader && matchEnum(item.status, status)
  })
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

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: t('dept.nameRequired'), trigger: 'blur' }],
}))

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
    ElMessage.success(t('dept.saveSuccess'))
    formVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await deptDelete(id)
  ElMessage.success(t('dept.deleteSuccess'))
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
