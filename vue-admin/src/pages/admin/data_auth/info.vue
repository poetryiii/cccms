<template>
  <a-drawer
    :visible="visible"
    :title="isUpdate ? '修改规则' : '添加规则'"
    width="44vw"
    :drawer-style="{ minWidth: '420px' }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="name">
        <a-input v-model="form.name" placeholder="请输入规则描述...">
          <template #prefix>规则描述</template>
        </a-input>
      </a-form-item>

      <a-form-item field="table_name">
        <a-select v-model="form.table_name" placeholder="选择目标表..." allow-search
          @change="onTableChange">
          <template #prefix>目标表</template>
          <a-option v-for="(label, key) in props.tables" :key="key" :value="key">{{ label }}（{{ key }}）</a-option>
        </a-select>
      </a-form-item>

      <a-row :gutter="12">
        <a-col :span="12">
          <a-form-item field="field">
            <a-select v-model="form.field" placeholder="选择字段..." allow-search
              :loading="fieldLoading">
              <template #prefix>目标字段</template>
              <a-option v-for="f in fieldList" :key="f.field" :value="f.field">
                {{ f.field }}<span style="color:#999;margin-left:8px">{{ f.comment }}</span>
              </a-option>
            </a-select>
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item field="rule_type">
            <a-select v-model="form.rule_type" placeholder="类型..." @change="onRuleTypeChange">
              <template #prefix>规则类型</template>
              <a-option value="hidden">hidden - 隐藏字段</a-option>
              <a-option value="readonly">readonly - 只读字段</a-option>
              <a-option value="mask_show">mask_show - 掩码显示</a-option>
              <a-option value="condition">condition - 条件筛选</a-option>
            </a-select>
          </a-form-item>
        </a-col>
      </a-row>

      <!-- 条件筛选专属区域 -->
      <template v-if="form.rule_type === 'condition'">
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item field="rule_operator">
              <a-select v-model="form.rule_operator" placeholder="选择操作符..." allow-search>
                <template #prefix>操作符</template>
                <a-option v-for="(label, key) in props.operators" :key="key" :value="key">
                  {{ label }}
                </a-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item field="rule_value">
              <a-input v-model="form.rule_value" placeholder="条件值（多个用逗号分隔）...">
                <template #prefix>条件值</template>
              </a-input>
            </a-form-item>
          </a-col>
        </a-row>
      </template>

      <a-divider orientation="center">绑定维度（选一项）</a-divider>

      <a-row :gutter="12">
        <a-col :span="6">
          <a-form-item><a-radio v-model="bindType" value="role">角色</a-radio></a-form-item>
        </a-col>
        <a-col :span="6">
          <a-form-item><a-radio v-model="bindType" value="dept">部门</a-radio></a-form-item>
        </a-col>
        <a-col :span="6">
          <a-form-item><a-radio v-model="bindType" value="post">岗位</a-radio></a-form-item>
        </a-col>
        <a-col :span="6">
          <a-form-item><a-radio v-model="bindType" value="user">用户</a-radio></a-form-item>
        </a-col>
      </a-row>

      <a-form-item v-if="bindType === 'role'">
        <a-select v-model="form.role_id" placeholder="选择角色..." allow-search allow-clear
          @change="onBindChange('role')">
          <template #prefix>绑定角色</template>
          <a-option v-for="item in props.roles" :key="item.id" :value="item.id">{{ item.role_name }}</a-option>
        </a-select>
      </a-form-item>
      <a-form-item v-if="bindType === 'dept'">
        <a-select v-model="form.dept_id" placeholder="选择部门..." allow-search allow-clear
          @change="onBindChange('dept')">
          <template #prefix>绑定部门</template>
          <a-option v-for="item in props.depts" :key="item.id" :value="item.id">{{ item.dept_name }}</a-option>
        </a-select>
      </a-form-item>
      <a-form-item v-if="bindType === 'post'">
        <a-select v-model="form.post_id" placeholder="选择岗位..." allow-search allow-clear
          @change="onBindChange('post')">
          <template #prefix>绑定岗位</template>
          <a-option v-for="item in props.posts" :key="item.id" :value="item.id">{{ item.post_name }}</a-option>
        </a-select>
      </a-form-item>
      <a-form-item v-if="bindType === 'user'">
        <a-input-number v-model="form.user_id" placeholder="输入用户ID..." :min="1" style="width:100%">
          <template #prefix>绑定用户ID</template>
        </a-input-number>
      </a-form-item>

      <a-form-item>
        <a-input-number v-model="form.priority" :disabled="true" style="width:100%">
          <template #prefix>优先级（自动）</template>
        </a-input-number>
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { Message } from '@arco-design/web-vue';
import { dataAuthCreate, dataAuthUpdate, dataAuthFields } from '@/api/admin/dataAuth.js';
import { useResetForm } from '@/hooks/form.js';

const props = defineProps({
  visible: false,
  data: undefined,
  tables: { type: Object, default: () => ({}) },
  operators: { type: Object, default: () => ({}) },
  roles: { type: Array, default: () => [] },
  depts: { type: Array, default: () => [] },
  posts: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:visible', 'done']);

const { form, isUpdate, setForm, resetForm } = useResetForm({
  name: '',
  table_name: undefined,
  field: '',
  rule_type: undefined,
  rule_operator: null,
  rule_value: null,
  role_id: null,
  dept_id: null,
  post_id: null,
  user_id: null,
  priority: null,
});

const bindType = ref('role');
const fieldList = ref([]);
const fieldLoading = ref(false);

// 级联：选表后加载字段列表
const onTableChange = async (val) => {
  form.field = '';
  fieldList.value = [];
  if (!val) return;
  fieldLoading.value = true;
  try {
    const { data } = await dataAuthFields({ table_name: val });
    fieldList.value = data || [];
  } catch (e) {
    fieldList.value = [];
  } finally {
    fieldLoading.value = false;
  }
};

const onRuleTypeChange = (val) => {
  if (val !== 'condition') {
    form.rule_operator = null;
    form.rule_value = null;
  }
};

// 优先级自动计算
const autoPriority = computed(() => {
  if (form.user_id) return 0;
  if (form.post_id) return 100;
  if (form.dept_id) return 200;
  return 300;
});

const onBindChange = (type) => {
  const keys = ['role_id', 'dept_id', 'post_id', 'user_id'];
  keys.forEach((k) => {
    if (!k.startsWith(type)) form[k] = null;
  });
  form.priority = autoPriority.value;
};

const cancelModal = () => emit('update:visible', false);

const okModal = async () => {
  const keys = ['role_id', 'dept_id', 'post_id', 'user_id'];
  keys.forEach((k) => { if (!form[k]) form[k] = null; });
  form.priority = autoPriority.value;

  if (isUpdate.value) {
    await dataAuthUpdate(form).then(() => Message.success('修改成功'));
  } else {
    await dataAuthCreate(form).then(() => Message.success('添加成功'));
  }
  emit('done');
  emit('update:visible');
};

watch(
  () => props.visible,
  async (visible) => {
    if (visible) {
      const d = props.data;
      setForm(d || {});
      if (d) {
        if (d.user_id) bindType.value = 'user';
        else if (d.post_id) bindType.value = 'post';
        else if (d.dept_id) bindType.value = 'dept';
        else bindType.value = 'role';
        // 编辑时加载该表的字段列表
        if (d.table_name) {
          fieldLoading.value = true;
          try {
            const { data: res } = await dataAuthFields({ table_name: d.table_name });
            fieldList.value = res || [];
          } catch (e) {
            fieldList.value = [];
          } finally {
            fieldLoading.value = false;
          }
        }
      }
      form.priority = d ? (d.priority ?? autoPriority.value) : autoPriority.value;
    } else {
      resetForm();
      fieldList.value = [];
    }
  },
);
</script>
