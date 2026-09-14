<template>
  <div class="art-fill">
    <el-alert
      class="rule-tip"
      type="info"
      :closable="false"
      show-icon
      title="预设档（角色的「数据范围」）决定基线，自定义行级规则在其上叠加（AND）；「全部数据」档位下规则不生效，「自定义规则」档位下若一条规则都没命中则看不到任何数据。绑定四项都不选 = 全局规则；指定了「目标表」的行级规则只在该表生效。目标表只能选「已接入数据权限」的表，字段随目标表级联。"
    />

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
        <el-form-item label="规则名">
          <el-input v-model="query.name" placeholder="请输入" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="目标表">
          <el-select v-model="query.table_name" clearable placeholder="全部" style="width: 170px">
            <el-option
              v-for="t in options.tables"
              :key="t.table"
              :label="t.label"
              :value="t.table"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="字段名">
          <el-input v-model="query.field" placeholder="请输入" clearable style="width: 140px" />
        </el-form-item>
      </template>

      <template #toolbar>
        <el-button v-auth="'cccms:data_rule:save'" type="primary" :icon="Plus" @click="openCreate">
          新增规则
        </el-button>
        <el-button v-auth="'cccms:data_rule:table_index'" :icon="Setting" @click="openTables">
          受控表
        </el-button>
      </template>

      <template #toolbar-right>
        <RecycleToggle :active="recycle" label="数据权限规则" @toggle="toggle" />
      </template>

      <template #target="{ row }">
        <el-tag v-if="row.table_name" effect="plain" size="small">
          {{ row.table_label || row.table_name }}
        </el-tag>
        <span v-else class="cond-empty">不限表</span>
      </template>

      <template #bind="{ row }">
        <span v-if="!hasBind(row)">全局</span>
        <template v-else>
          <el-tag v-if="row.user_name" size="small" effect="plain">用户：{{ row.user_name }}</el-tag>
          <el-tag v-if="row.post_name" size="small" effect="plain" class="bind-tag">
            岗位：{{ row.post_name }}
          </el-tag>
          <el-tag v-if="row.role_name" size="small" effect="plain" class="bind-tag">
            角色：{{ row.role_name }}
          </el-tag>
          <el-tag v-if="row.dept_names" size="small" effect="plain" class="bind-tag">
            部门：{{ row.dept_names }}
          </el-tag>
        </template>
      </template>

      <template #action_type="{ row }">
        <el-tag :type="actionTag(row.action)" effect="light" size="small">
          {{ actionLabel(row.action) }}
        </el-tag>
      </template>

      <template #condition="{ row }">
        <code v-if="row.action === 'row'" class="cond">
          {{ row.field }} {{ operatorLabel(row.operator) }} {{ row.value }}
        </code>
        <span v-else class="cond-empty">—</span>
      </template>

      <template #action="{ row }">
        <el-button v-auth="'cccms:data_rule:update'" link type="primary" @click="openEdit(row)">编辑</el-button>
        <el-popconfirm title="确定删除该规则？" @confirm="onDelete(row.id)">
          <template #reference>
            <el-button v-auth="'cccms:data_rule:delete'" link type="danger">删除</el-button>
          </template>
        </el-popconfirm>
      </template>
    </ArtTable>

    <el-dialog
      v-model="formVisible"
      :title="form.id ? '编辑规则' : '新增规则'"
      width="660px"
      top="6vh"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="96px">
        <el-form-item label="规则名" prop="name">
          <el-input v-model="form.name" placeholder="如 客服只看未关闭工单" />
        </el-form-item>

        <!-- 绑定对象：与角色管理的「权限节点」同一套结构（上面按钮、下面节点） -->
        <el-form-item label="指定用户" prop="user_id">
          <ArtNodePicker
            v-model="form.user_id"
            :search="searchUsers"
            :selected-row="selectedUser"
            :label-fn="userLabel"
            placeholder="输入账号或昵称搜索"
          />
          <div class="form-tip">按关键词模糊搜索，默认不加载；清空 = 不限。命中条件：登录账号就是这个用户。</div>
        </el-form-item>

        <el-form-item label="指定岗位" prop="post_id">
          <ArtNodePicker v-model="form.post_id" :data="options.posts" />
          <div class="form-tip">不限 = 任何岗位；命中条件：用户被分配了该岗位。</div>
        </el-form-item>

        <el-form-item label="指定角色" prop="role_id">
          <ArtNodePicker v-model="form.role_id" :data="options.roles" />
          <div class="form-tip">不限 = 任何角色；选中哪个角色就只匹配哪个，不会自动带上子角色。</div>
        </el-form-item>

        <el-form-item label="指定部门" prop="dept_ids">
          <ArtNodePicker v-model="form.dept_ids" :data="options.depts" multiple />
          <div class="form-tip">不限 = 任何部门；绑定部门<b>含下级</b>：绑「总公司」会覆盖它下面所有部门的人。四项命中任意一项即生效。</div>
        </el-form-item>

        <!-- 目标表 + 字段（级联选择） -->
        <el-form-item label="目标表" prop="field">
          <el-cascader
            v-model="targetValue"
            :options="cascaderOptions"
            :props="{ expandTrigger: 'hover' }"
            filterable
            clearable
            class="target-cascader"
            placeholder="选择目标表与字段"
          />
          <div class="form-tip">{{ fieldHint }}</div>
        </el-form-item>

        <el-form-item label="规则动作" prop="action">
          <el-select v-model="form.action" style="width: 100%">
            <el-option
              v-for="a in options.actions"
              :key="a"
              :label="`${actionLabel(a)}（${a}）`"
              :value="a"
            />
          </el-select>
          <div class="form-tip">row = 行级过滤；其余为字段级（作用于出参，readonly 作用于入参）。</div>
        </el-form-item>

        <template v-if="form.action === 'row'">
          <el-form-item label="操作符" prop="operator">
            <el-select v-model="form.operator" style="width: 100%">
              <el-option
                v-for="op in operatorOptions"
                :key="op"
                :label="`${operatorLabel(op)}（${op}）`"
                :value="op"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="取值类型" prop="value_type">
            <el-radio-group v-model="form.value_type">
              <el-radio value="static">静态值</el-radio>
              <el-radio value="dynamic">动态变量</el-radio>
            </el-radio-group>
            <div class="form-tip">
              动态变量按<b>当前登录用户</b>实时解析，可表达「本部门及以下」这类动态范围。
            </div>
          </el-form-item>

          <el-form-item label="取值" prop="value">
            <el-select
              v-if="form.value_type === 'dynamic'"
              v-model="valueTokens"
              multiple
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              placeholder="选择变量，也可直接输入字面量"
            >
              <el-option
                v-for="v in options.value_vars ?? []"
                :key="v.value"
                :label="`${v.label}（${v.value}）`"
                :value="v.value"
              />
            </el-select>
            <el-input v-else v-model="form.value" placeholder="如 1，或用英文逗号分隔：1,2,3" />
            <div class="form-tip">{{ valueHint }}</div>
          </el-form-item>
        </template>

        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" placeholder="默认 0" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">确定</el-button>
      </template>
    </el-dialog>

    <!-- 受控表：只有登记在此的表才会出现在「目标表」候选里 -->
    <el-dialog
      v-model="tableVisible"
      title="数据权限受控表"
      width="820px"
      top="8vh"
      :close-on-click-modal="false"
    >
      <el-alert
        class="rule-tip"
        type="info"
        :closable="false"
        show-icon
        title="只有登记在这里的表才会出现在「目标表」候选中，其余表一律隐藏。前提是该表的业务逻辑已调用 DataScope，否则配了规则也不会生效。"
      />

      <div class="tbl-add">
        <el-select
          v-model="addForm.table_name"
          filterable
          clearable
          placeholder="选择要加入受控表的表"
          class="tbl-add-select"
        >
          <el-option
            v-for="t in tableData.available"
            :key="t.table"
            :label="`${t.label}（${t.full}）`"
            :value="t.table"
          />
        </el-select>
        <el-input v-model="addForm.label" placeholder="语义名（留空取表注释）" class="tbl-add-label" />
        <el-button
          v-auth="'cccms:data_rule:table_save'"
          type="primary"
          :disabled="!addForm.table_name"
          :loading="tableSaving"
          @click="onAddTable"
        >
          加入
        </el-button>
      </div>

      <el-table :data="tableData.list" size="small" border>
        <el-table-column label="表名" width="180">
          <template #default="{ row }">
            <span class="tbl-name">{{ row.table_name }}</span>
            <el-tag v-if="!row.exists" type="danger" size="small" effect="plain">表不存在</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="语义名" min-width="170">
          <template #default="{ row }">
            <el-input v-model="row.label" size="small" @change="onUpdateTable(row)" />
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="150">
          <template #default="{ row }">
            <el-input v-model="row.remark" size="small" @change="onUpdateTable(row)" />
          </template>
        </el-table-column>
        <el-table-column prop="field_count" label="字段数" width="80" align="center" />
        <el-table-column label="受控" width="80" align="center">
          <template #default="{ row }">
            <el-switch
              v-model="row.status"
              :active-value="1"
              :inactive-value="0"
              @change="onUpdateTable(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ row }">
            <el-popconfirm
              title="移除后，该表上的规则会暂停生效，确定？"
              @confirm="onRemoveTable(row)"
            >
              <template #reference>
                <el-button v-auth="'cccms:data_rule:table_delete'" link type="danger">移除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
        <template #empty>暂未登记受控表</template>
      </el-table>

      <template #footer>
        <el-button @click="tableVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:data_rule' })

import { computed, reactive, ref, watch } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus, Setting } from '@element-plus/icons-vue'
import ArtTable from '@/components/core/ArtTable.vue'
import RecycleToggle from '@/components/core/RecycleToggle.vue'
import { useRecycle } from '@/composables/useRecycle'
import ArtNodePicker from '@/components/core/ArtNodePicker.vue'
import { useTable } from '@/composables/useTable'
import {
  dataRuleDelete,
  dataRuleList,
  dataRuleOptions,
  dataRuleSave,
  dataRuleUpdate,
  dataRuleUsers,
  dataScopeTableDelete,
  dataScopeTableList,
  dataScopeTableSave,
  dataScopeTableUpdate,
  type DataRuleOption,
  type DataRuleOptions,
  type DataRuleRow,
  type DataScopeTableAvailable,
  type DataScopeTableRow,
} from '@/api/dataRule'
import type { ArtTableColumn } from '@/types/table'

interface Query {
  name: string
  table_name: string
  field: string
}

/** 目标表 → 字段 的级联节点 */
interface CascadeNode {
  value: string
  label: string
  children?: CascadeNode[]
}

const ACTION_LABELS: Record<string, string> = {
  row: '行级过滤',
  hidden: '字段隐藏',
  readonly: '字段只读',
  mask: '字段脱敏',
  encrypt: '字段加密',
}

/** 操作符语义（后端 options.operator_labels 优先，这里做兜底） */
const OPERATOR_LABELS: Record<string, string> = {
  '=': '等于',
  '!=': '不等于',
  '<>': '不等于',
  '>': '大于',
  '>=': '大于等于',
  '<': '小于',
  '<=': '小于等于',
  like: '包含',
  in: '属于',
  between: '介于',
}

/** 这些类型用 like 没有意义；反过来，字符串列也不该出现比较操作符 */
const NUMERIC_TYPES = [
  'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
  'decimal', 'float', 'double',
  'date', 'datetime', 'timestamp', 'time', 'year',
]

const columns: ArtTableColumn[] = [
  { prop: 'id', label: 'ID', width: 70 },
  { prop: 'name', label: '规则名', minWidth: 140 },
  { prop: 'table_name', label: '目标表', width: 160, slot: 'target' },
  { prop: 'field', label: '字段', width: 120 },
  { prop: 'bind', label: '绑定对象', minWidth: 200, slot: 'bind' },
  // prop 用 action_type 而不是 action：下面还有一个「操作」列，重复 key 会让 v-for 出问题
  { prop: 'action_type', label: '动作', width: 106, align: 'center', slot: 'action_type' },
  { prop: 'condition', label: '条件', minWidth: 150, slot: 'condition' },
  { prop: 'action', label: '操作', width: 130, fixed: 'right', slot: 'action', lockVisible: true },
]

// 回收站开关：必须在 useTable 之前（列表闭包在 setup 阶段就会执行一次）
const { recycle, toggle, onRestore, onForceDelete } = useRecycle('data_rule', {
  reload: () => search(),
})

const {
  list, loading, total, page, limit, query, load, search, reset, onPageChange, onLimitChange,
} = useTable<DataRuleRow, Query>({
  api: (params) => dataRuleList({ ...params, trashed: recycle.value ? 1 : 0 }),
  initialQuery: { name: '', table_name: '', field: '' },
})

const options = ref<DataRuleOptions>({
  users: [],
  posts: [],
  roles: [],
  depts: [],
  tables: [],
  actions: ['row', 'hidden', 'readonly', 'mask', 'encrypt'],
  operators: ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'in', 'between'],
  operator_labels: {},
  value_types: ['static', 'dynamic'],
  value_vars: [],
})

async function loadOptions(): Promise<void> {
  options.value = await dataRuleOptions()
}

const actionLabel = (action?: string): string => ACTION_LABELS[action ?? ''] ?? action ?? '-'

/** 操作符语义化展示：优先用后端返回的映射，缺失时回退到本地表 */
function operatorLabel(operator?: string): string {
  const map = options.value.operator_labels ?? OPERATOR_LABELS
  return map[operator ?? ''] ?? operator ?? '-'
}

function actionTag(action?: string): 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  switch (action) {
    case 'row':
      return 'primary'
    case 'hidden':
      return 'warning'
    case 'readonly':
      return 'info'
    case 'encrypt':
      return 'danger'
    default:
      return 'success'
  }
}

function hasBind(row: DataRuleRow): boolean {
  return !!(row.user_id || row.post_id || row.role_id || (row.dept_ids?.length ?? 0) > 0)
}

/* ---- 表单 ---- */
const formRef = ref<FormInstance>()
const formVisible = ref(false)
const saving = ref(false)

const emptyForm = {
  id: 0,
  name: '',
  // 绑定项留空而非 0：没有选中时显示 placeholder，而不是「0」
  user_id: null as number | null,
  post_id: null as number | null,
  role_id: null as number | null,
  dept_ids: [] as number[],
  table_name: '',
  field: '',
  action: 'row',
  operator: '=',
  value: '',
  value_type: 'static',
  sort: null as number | null,
  remark: '',
}
const form = reactive<Record<string, any>>({ ...emptyForm })

const rules: FormRules = {
  name: [{ required: true, message: '请输入规则名', trigger: 'blur' }],
  field: [{ required: true, message: '请选择目标表与字段', trigger: 'change' }],
}

/** 目标表 → 字段 的级联候选；「不限表」用 __none__ 表示，子节点是各表字段并集 */
const cascaderOptions = computed<CascadeNode[]>(() => {
  const tables = options.value.tables ?? []

  const union: CascadeNode[] = []
  const seen = new Set<string>()
  for (const t of tables) {
    for (const f of t.fields) {
      if (seen.has(f.field)) {
        continue
      }
      seen.add(f.field)
      union.push({
        value: f.field,
        label: f.label === f.field ? f.field : `${f.field}（${f.label}）`,
      })
    }
  }

  return [
    { value: '__none__', label: '不限表（对已接入的模块生效）', children: union },
    ...tables.map((t) => ({
      value: t.table,
      label: `${t.label}（${t.full}）`,
      children: t.fields.map((f) => ({
        value: f.field,
        label: f.label === f.field ? f.field : `${f.field}（${f.label}）`,
      })),
    })),
  ]
})

/** 级联选择器绑定：[目标表, 字段] ↔ form.table_name / form.field */
const targetValue = computed<string[]>({
  get: () => [form.table_name || '__none__', form.field || ''],
  set: (val) => {
    const [table = '__none__', field = ''] = Array.isArray(val) ? val : []
    form.table_name = table === '__none__' ? '' : table
    form.field = field
  },
})

const currentFieldType = computed(() => {
  const tables = options.value.tables ?? []
  if (form.table_name) {
    const chosen = tables.find((t) => t.table === form.table_name)
    return chosen?.fields.find((f) => f.field === form.field)?.type ?? ''
  }
  for (const t of tables) {
    const hit = t.fields.find((f) => f.field === form.field)
    if (hit) {
      return hit.type
    }
  }
  return ''
})

const operatorOptions = computed(() => {
  const all = options.value.operators ?? []
  const type = currentFieldType.value
  if (!type) {
    return all
  }
  return NUMERIC_TYPES.includes(type)
    ? all.filter((op) => op !== 'like')
    : all.filter((op) => !['>', '>=', '<', '<='].includes(op))
})

// 切换字段后原来的操作符可能不再适用
watch(operatorOptions, (available) => {
  if (!available.includes(form.operator)) {
    form.operator = '='
  }
})

const fieldHint = computed(() => {
  if (!form.table_name) {
    return '不限表：对所有已接入数据权限的模块生效，字段名需在各表都存在；不确定时建议指定目标表。'
  }
  return '先选「目标表」再选字段；只列该表已有的字段，括号里是字段注释。'
})

/* ---- 行级取值：动态变量 ---- */

/** 动态取值在界面上是多选的 token，落库仍是逗号分隔字符串 */
const valueTokens = computed<string[]>({
  get: () =>
    form.value
      ? String(form.value)
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean)
      : [],
  set: (val) => {
    form.value = (Array.isArray(val) ? val : []).join(',')
  },
})

const valueHint = computed(() => {
  if (form.value_type !== 'dynamic') {
    return 'in / between 用英文逗号分隔（between 形如 10,20）。'
  }
  if (form.operator === 'between') {
    return '需要正好两个值，例如 {user.id},100。'
  }
  if (form.operator === 'in') {
    return '多个变量会合并成一个集合走「属于(IN)」，例如 dept_id in {dept.subtree}。'
  }
  return '变量展开成多个值时会按「属于(IN)」处理；建议改用「属于」操作符以表达集合语义。'
})

/* ---- 绑定用户：远程模糊搜索（ArtNodePicker 自己防抖，这里只管取数与回显） ---- */
/** 已选中的用户（回显用：结果集里未必包含它，ArtNodePicker 会把它钉在列表最前） */
const selectedUser = ref<DataRuleOption | null>(null)

/** 用户行展示：账号（昵称）——作为 ArtNodePicker 的 labelFn */
function userLabel(u: Record<string, any>): string {
  return u.nickname ? `${u.username}（${u.nickname}）` : (u.username ?? String(u.id))
}

async function searchUsers(keyword: string): Promise<DataRuleOption[]> {
  return dataRuleUsers({ keyword })
}

/* ---- 受控表维护 ---- */
const tableVisible = ref(false)
const tableSaving = ref(false)
const tableData = reactive<{ list: DataScopeTableRow[]; available: DataScopeTableAvailable[] }>({
  list: [],
  available: [],
})
const addForm = reactive({ table_name: '', label: '' })

async function loadTables(): Promise<void> {
  const data = await dataScopeTableList()
  tableData.list = data.list
  tableData.available = data.available
}

async function openTables(): Promise<void> {
  tableVisible.value = true
  await loadTables()
}

async function onAddTable(): Promise<void> {
  if (!addForm.table_name) {
    return
  }
  tableSaving.value = true
  try {
    await dataScopeTableSave({ table_name: addForm.table_name, label: addForm.label })
    ElMessage.success('已加入受控表')
    addForm.table_name = ''
    addForm.label = ''
    await loadTables()
    // 目标表候选变了，重新拉一次
    await loadOptions()
  } finally {
    tableSaving.value = false
  }
}

async function onUpdateTable(row: DataScopeTableRow): Promise<void> {
  await dataScopeTableUpdate({
    id: row.id,
    label: row.label,
    status: row.status,
    remark: row.remark,
  })
  ElMessage.success('已保存')
  await loadOptions()
}

async function onRemoveTable(row: DataScopeTableRow): Promise<void> {
  const res = await dataScopeTableDelete(row.id)
  const paused = res?.rules ?? 0
  ElMessage.success(paused > 0 ? `已移除，该表上的 ${paused} 条规则暂停生效` : '已移除')
  await loadTables()
  await loadOptions()
}

function openCreate(): void {
  Object.assign(form, emptyForm, { dept_ids: [] })
  selectedUser.value = null
  formVisible.value = true
}

function openEdit(record: DataRuleRow): void {
  Object.assign(form, emptyForm, record, {
    dept_ids: [...(record.dept_ids ?? [])],
    table_name: record.table_name ?? '',
    // 0 = 不限：转成 null，让控件显示 placeholder
    user_id: record.user_id || null,
    post_id: record.post_id || null,
    role_id: record.role_id || null,
    value_type: record.value_type || 'static',
    sort: record.sort || null,
  })
  // 回显已绑定的用户（ArtNodePicker 会把它钉在列表最前）
  selectedUser.value = record.user_id
    ? { id: record.user_id, username: record.user_name || `#${record.user_id}` }
    : null
  formVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    // 显式构造提交体，避免把绑定名称等只读字段回传
    const payload: Record<string, unknown> = {
      id: form.id,
      name: form.name,
      user_id: form.user_id || 0,
      post_id: form.post_id || 0,
      role_id: form.role_id || 0,
      dept_ids: form.dept_ids,
      table_name: form.table_name,
      field: form.field,
      action: form.action,
      operator: form.action === 'row' ? form.operator : '=',
      value: form.action === 'row' ? form.value : '',
      value_type: form.action === 'row' ? form.value_type || 'static' : 'static',
      sort: form.sort ?? 0,
      remark: form.remark,
    }

    if (form.id) {
      await dataRuleUpdate(payload)
    } else {
      await dataRuleSave(payload)
    }
    ElMessage.success('保存成功')
    formVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onDelete(id: number): Promise<void> {
  await dataRuleDelete(id)
  ElMessage.success('删除成功')
  load()
}

loadOptions()
</script>

<style scoped>
.rule-tip {
  flex-shrink: 0;
  margin-bottom: 12px;
}

.bind-tag {
  margin-left: 2px;
}

.cond {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: var(--art-main);
}

.cond-empty {
  color: var(--art-muted);
}

.target-cascader {
  width: 100%;
}

.tbl-add {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.tbl-add-select {
  width: 320px;
}

.tbl-add-label {
  width: 220px;
}

.tbl-name {
  margin-right: 6px;
}

.form-tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-muted);
}
</style>
