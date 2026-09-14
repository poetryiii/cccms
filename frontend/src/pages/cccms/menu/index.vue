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
          新增
        </el-button>
        <span class="toolbar-tip">
          按钮节点由控制器 #[Permission] 注解经 cccms:perm-scan 生成，人工改动会在下次扫描时保留
        </span>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" label="菜单" @toggle="toggle" />
      </template>

      <template #type="{ row }">
        <el-tag :type="typeTag(row.type)" effect="light">{{ typeText(row.type) }}</el-tag>
      </template>

      <template #node="{ row }">
        <code class="node-code">{{ row.node }}</code>
      </template>

      <template #status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" round>
          {{ row.status === 1 ? '显示' : '隐藏' }}
        </el-tag>
      </template>

      <template #action="{ row }">
        <el-button
          v-if="row.type !== 3"
          v-auth="'cccms:menu:save'"
          link
          type="primary"
          @click="openCreate(row.id)"
        >
          新增子项
        </el-button>
        <el-button v-auth="'cccms:menu:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
        <el-popconfirm title="确定删除该节点？" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:menu:delete'" link type="danger">删除</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑菜单' : '新增菜单'"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="上级节点" prop="parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentOptions"
            :props="{ label: 'title', children: 'children' }"
            node-key="id"
            check-strictly
            placeholder="顶级"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">目录</el-radio>
            <el-radio :value="2">菜单</el-radio>
            <el-radio :value="3">按钮</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="名称" prop="title">
          <el-input v-model="form.title" placeholder="请输入名称" />
        </el-form-item>
        <el-form-item label="权限节点" prop="node">
          <el-input v-model="form.node" placeholder="如 cccms:user:index" />
          <div class="form-tip">命名规范：{插件}:{模块}:{动作}，全局唯一</div>
        </el-form-item>
        <el-form-item v-if="form.type !== 3" label="路由地址" prop="path">
          <el-input v-model="form.path" placeholder="如 /cccms/user" />
        </el-form-item>
        <el-form-item v-if="form.type === 2" label="组件路径" prop="component">
          <el-input v-model="form.component" placeholder="如 cccms/user/index" />
        </el-form-item>
        <el-form-item v-if="form.type !== 3" label="图标" prop="icon">
          <el-popover trigger="click" :width="372" placement="bottom-start">
            <template #reference>
              <el-input v-model="form.icon" readonly placeholder="点击选择图标" style="width: 100%">
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
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
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
defineOptions({ name: 'cccms:menu' })

import { reactive, ref } from 'vue'
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

const typeTextMap: Record<number, string> = { 1: '目录', 2: '菜单', 3: '按钮' }
const typeTagMap: Record<number, 'primary' | 'success' | 'warning'> = {
  1: 'primary',
  2: 'success',
  3: 'warning',
}

const columns: ArtTableColumn[] = [
  { prop: 'title', label: '名称', minWidth: 200 },
  { prop: 'type', label: '类型', width: 90, align: 'center', slot: 'type' },
  { prop: 'node', label: '权限节点', minWidth: 190, slot: 'node' },
  { prop: 'path', label: '路由', width: 160 },
  { prop: 'component', label: '组件', width: 190, defaultHidden: true },
  { prop: 'sort', label: '排序', width: 80, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', slot: 'status' },
  { prop: 'action', label: '操作', width: 210, fixed: 'right', slot: 'action', lockVisible: true },
]

const loading = ref(false)
const list = ref<MenuNode[]>([])
const parentOptions = ref<MenuNode[]>([])

const typeText = (t: number): string => typeTextMap[t] ?? '-'
const typeTag = (t: number): 'primary' | 'success' | 'warning' => typeTagMap[t] ?? 'primary'

// 回收站开关（load() 在本文件末尾首次执行，那时它已初始化）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('menu', {
  reload: () => load(),
})

async function load(): Promise<void> {
  loading.value = true
  try {
    // trashed=true → 后端返回平铺的已删节点（含隐藏节点），恢复后父子关系自动接上
    list.value = await menuTree(recycle.value)
    parentOptions.value = [{ id: 0, title: '顶级', children: list.value } as unknown as MenuNode]
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

const rules: FormRules = {
  title: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  node: [{ required: true, message: '请输入权限节点', trigger: 'blur' }],
}

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
    ElMessage.success('保存成功')
    formVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await menuDelete(id)
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
