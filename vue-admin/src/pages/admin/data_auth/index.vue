<template>
  <Table
    :fields="table.fields"
    :ignoreFields="table.ignoreFields"
    v-model:form="table.form"
    v-model:columns="table.columns"
    v-model:pagination="table.pagination"
    :data="table.data"
    @reload="getDatas"
  >
    <template #headerButton>
      <a-button
        type="primary"
        v-permission="'admin/data_auth/create'"
        @click="editData()"
      >
        添加规则
      </a-button>
    </template>

    <template #table_name="{ record }">
      <a-tag color="arcoblue">{{ tableNameMap[record.table_name] || record.table_name }}</a-tag>
    </template>

    <template #rule_type="{ record }">
      <a-tag :color="ruleTypeColor(record.rule_type)">
        {{ ruleTypeLabel(record.rule_type) }}
      </a-tag>
    </template>

    <template #condition="{ record }">
      <span v-if="record.rule_type === 'condition' && record.rule_operator">
        <a-tag size="small">{{ record.rule_operator }}</a-tag>
        {{ record.rule_value }}
      </span>
      <span v-else>-</span>
    </template>

    <template #binding="{ record }">
      <span v-if="record.user_id">用户</span>
      <span v-else-if="record.post_id">岗位</span>
      <span v-else-if="record.dept_id">部门</span>
      <span v-else-if="record.role_id">角色</span>
      <span v-else>-</span>
    </template>

    <template #status="{ record }">
      <a-switch
        v-model:model-value="record.status"
        :checked-value="1"
        :unchecked-value="0"
        @change="changeStatus(record)"
      />
    </template>

    <template #operation="{ record }">
      <a-button type="text" size="mini" @click="editData(record)" v-permission="'admin/data_auth/update'">
        <template #icon><i class="ri-edit-line"></i></template>
      </a-button>
      <Popconfirm
        content="确定要删除此规则吗？"
        type="warning"
        position="left"
        @ok="delData(record)"
      >
        <a-button type="text" size="mini" v-permission="'admin/data_auth/delete'">
          <template #icon><i class="ri-delete-bin-line" style="color: rgb(var(--danger-6))"></i></template>
        </a-button>
      </Popconfirm>
    </template>
  </Table>

  <Info
    v-model:visible="editStatus.data.visible"
    :data="editStatus.data.currentData"
    :tables="table.tables"
    :operators="table.operators"
    :roles="table.roles"
    :depts="table.depts"
    :posts="table.posts"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive } from 'vue';
import { Message } from '@arco-design/web-vue';
import { dataAuthQuery, dataAuthUpdate, dataAuthDelete } from '@/api/admin/dataAuth.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import Info from './info.vue';

const tableNameMap = {
  sys_user: '用户表',
  sys_role: '角色表',
  sys_dept: '部门表',
  sys_post: '岗位表',
  sys_menu: '菜单表',
  sys_log: '日志表',
  sys_file: '附件表',
  sys_config: '配置表',
  sys_dict_type: '字典类型',
  sys_dict_data: '字典数据',
  sys_crontab: '定时任务',
};

const ruleTypeColor = (t) => {
  const map = { hidden: 'red', readonly: 'orange', mask_show: 'purple', condition: 'blue' };
  return map[t] || 'gray';
};
const ruleTypeLabel = (t) => {
  const map = { hidden: '隐藏', readonly: '只读', mask_show: '掩码', condition: '条件' };
  return map[t] || t;
};

const getDatas = async () => {
  const { data } = await dataAuthQuery(table.form);
  table.fields = data.fields;
  table.data = data.data;
  table.tables = data.tables;
  table.operators = data.operators;
  table.roles = data.roles;
  table.depts = data.depts;
  table.posts = data.posts;
};

const changeStatus = (record) => {
  dataAuthUpdate({ id: record.id, status: record.status }).then(() => {
    Message.success('更新成功');
  });
};

const editStatus = useFormEdit();
const editData = (row) => editStatus.updateFormEditStatus(row);

const delData = (record) => {
  dataAuthDelete({ id: record.id }).then(() => {
    Message.success('删除成功');
    getDatas();
  });
};

const table = reactive({
  form: { table_name: '' },
  pagination: false,
  data: [],
  tables: {},
  operators: {},
  roles: [],
  depts: [],
  posts: [],
  fields: [],
  ignoreFields: ['id', 'rule_value', 'operation'],
  columns: [
    { dataIndex: 'name', title: '规则描述', width: 160, ellipsis: true, tooltip: true },
    { dataIndex: 'table_name', title: '目标表', width: 110, slotName: 'table_name' },
    { dataIndex: 'field', title: '字段', width: 120, ellipsis: true },
    { dataIndex: 'rule_type', title: '规则类型', width: 70, slotName: 'rule_type' },
    { dataIndex: 'condition', title: '条件', width: 150, slotName: 'condition', ellipsis: true },
    { dataIndex: 'binding', title: '绑定维度', width: 70, slotName: 'binding' },
    { dataIndex: 'priority', title: '优先级', width: 70, align: 'center' },
    { dataIndex: 'status', title: '状态', width: 60, slotName: 'status', align: 'center' },
    { dataIndex: 'create_time', title: '创建时间', width: 160, ellipsis: true },
    {
      dataIndex: 'operation', title: '操作', width: 80, align: 'center',
      fixed: 'right', slotName: 'operation',
    },
  ],
});
</script>
