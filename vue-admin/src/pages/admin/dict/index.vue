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
      <a-button type="primary" v-permission="'admin/dict/create'" @click="editType()">添加</a-button>
    </template>
    <template #data="{ record }">
      <a-button type="text" size="mini" @click="openData(record)">查看数据</a-button>
    </template>
    <template #operation="{ record }">
      <a-button type="text" size="mini" @click="editType(record)" v-permission="'admin/dict/update'">
        <template #icon><i class="ri-edit-line"></i></template>
      </a-button>
      <Popconfirm content="确定要删除吗？" type="warning" position="left" @ok="delType(record)">
        <a-button type="text" size="mini" v-permission="'admin/dict/delete'">
          <template #icon><i class="ri-delete-bin-line" style="color: rgb(var(--danger-6))"></i></template>
        </a-button>
      </Popconfirm>
    </template>
  </Table>
  <TypeInfo v-model:visible="typeEditStatus.data.visible" :data="typeEditStatus.data.currentData" @done="getDatas" />
  <DataInfo v-model:visible="dataVisible" :typeId="currentTypeId" :typeName="currentTypeName" />
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Message } from '@arco-design/web-vue';
import { dictTypeList, dictTypeDelete } from '@/api/admin/dict.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import TypeInfo from './type-info.vue';
import DataInfo from './data-info.vue';

const currentTypeId = ref(0);
const currentTypeName = ref('');
const dataVisible = ref(false);
let typeEditStatus = useFormEdit();

const getDatas = async () => {
  const { data } = await dictTypeList(table.form);
  table.fields = data.fields;
  table.data = data.data;
  table.pagination.current = data.current_page || 1;
  table.pagination.total = data.total || 0;
};
const editType = (row) => typeEditStatus.updateFormEditStatus(row);
const openData = (record) => { currentTypeId.value = record.id; currentTypeName.value = record.dict_name; dataVisible.value = true; };
const delType = (record) => { dictTypeDelete({ id: record.id }).then(() => { Message.success('删除成功'); getDatas(); }); };

const table = reactive({
  form: {},
  pagination: { current: 1, pageSize: 15 },
  data: [], fields: [],
  ignoreFields: ['data', 'operation'],
  columns: [
    { dataIndex: 'dict_name', title: '字典名称', width: 150, ellipsis: true },
    { dataIndex: 'dict_type', title: '字典标识', width: 150, ellipsis: true },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'remark', title: '备注', width: 200, ellipsis: true },
    { dataIndex: 'data', title: '字典数据', width: 100, slotName: 'data' },
    { dataIndex: 'create_time', title: '创建时间', width: 180 },
    { dataIndex: 'operation', title: '操作', width: 80, align: 'center', fixed: 'right', slotName: 'operation' },
  ],
});
</script>
