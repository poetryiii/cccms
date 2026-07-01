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
      <a-button type="primary" v-permission="'admin/crontab/create'" @click="editData()">添加</a-button>
    </template>
    <template #status="{ record }">
      <a-switch v-model:model-value="record.status" :checked-value="1" :unchecked-value="0" @change="toggleStatus(record, $event)" />
    </template>
    <template #operation="{ record }">
      <a-button type="text" size="mini" @click="execOne(record)" v-permission="'admin/crontab/execute'"><template #icon><i class="ri-play-line" style="color:rgb(var(--success-6))"></i></template></a-button>
      <a-button type="text" size="mini" @click="showLog(record)"><template #icon><i class="ri-file-list-line"></i></template></a-button>
      <a-button type="text" size="mini" @click="editData(record)" v-permission="'admin/crontab/update'"><template #icon><i class="ri-edit-line"></i></template></a-button>
      <Popconfirm content="确定要删除吗？" type="warning" position="left" @ok="delData(record)">
        <a-button type="text" size="mini" v-permission="'admin/crontab/delete'"><template #icon><i class="ri-delete-bin-line" style="color: rgb(var(--danger-6))"></i></template></a-button>
      </Popconfirm>
    </template>
  </Table>
  <Info v-model:visible="editStatus.data.visible" :data="editStatus.data.currentData" @done="getDatas" />
  <a-drawer :visible="logVisible" title="执行日志" width="50vw" @cancel="logVisible=false">
    <a-table :columns="logColumns" :data="logList" :pagination="false" size="small" />
  </a-drawer>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Message } from '@arco-design/web-vue';
import { crontabList, crontabDelete, crontabToggle, crontabExecute, crontabLog } from '@/api/admin/crontab.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import Info from './info.vue';

let editStatus = useFormEdit();
const logVisible = ref(false);
const logList = ref([]);
const logColumns = [
  { dataIndex: 'id', title: 'ID', width: 60 }, { dataIndex: 'status', title: '状态', width: 60 }, { dataIndex: 'exec_time', title: '耗时(秒)', width: 80 },
  { dataIndex: 'result', title: '结果', ellipsis: true }, { dataIndex: 'create_time', title: '时间', width: 160 },
];
const getDatas = async () => {
  const { data } = await crontabList(table.form);
  table.fields = data.fields; table.data = data.data;
  table.pagination.current = data.current_page || 1; table.pagination.total = data.total || 0;
};
const editData = (row) => editStatus.updateFormEditStatus(row);
const delData = (record) => { crontabDelete({ id: record.id }).then(() => { Message.success('删除成功'); getDatas(); }); };
const toggleStatus = (record, val) => { crontabToggle({ id: record.id, status: val }).then(() => getDatas()); };
const execOne = (record) => { crontabExecute({ id: record.id }).then((res) => { Message.success(res.msg || '执行成功'); getDatas(); }).catch(() => {}); };
const showLog = async (record) => { const { data } = await crontabLog({ crontab_id: record.id }); logList.value = data.data || []; logVisible.value = true; };
const table = reactive({
  form: {}, pagination: { current: 1, pageSize: 15 }, data: [], fields: [],
  ignoreFields: ['operation'],
  columns: [
    { dataIndex: 'title', title: '任务名称', width: 150, ellipsis: true }, { dataIndex: 'command', title: '执行命令', width: 200, ellipsis: true },
    { dataIndex: 'rule', title: 'Cron表达式', width: 120 }, { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'last_time', title: '上次执行', width: 160 }, { dataIndex: 'create_time', title: '创建时间', width: 160 },
    { dataIndex: 'operation', title: '操作', width: 140, align: 'center', fixed: 'right', slotName: 'operation' },
  ],
});
</script>
