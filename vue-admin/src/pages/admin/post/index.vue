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
        v-permission="'admin/post/create'"
        @click="editData()"
      >
        添加
      </a-button>
    </template>
    <template #status="{ record }">
      <a-switch
        v-model:model-value="record.status"
        :checked-value="1"
        :unchecked-value="0"
        @change="changeStatusFun(record)"
      />
    </template>
    <template #operation="{ record }">
      <a-button
        type="text"
        size="mini"
        @click="editData(record)"
        v-permission="'admin/post/update'"
      >
        <template #icon>
          <i class="ri-edit-line"></i>
        </template>
      </a-button>
      <Popconfirm
        content="确定要删除吗？"
        type="warning"
        position="left"
        @ok="delData(record)"
      >
        <a-button type="text" size="mini" v-permission="'admin/post/delete'">
          <template #icon>
            <i
              class="ri-delete-bin-line"
              style="color: rgb(var(--danger-6))"
            ></i>
          </template>
        </a-button>
      </Popconfirm>
    </template>
  </Table>
  <Info
    v-model:visible="postEditStatus.data.visible"
    :data="postEditStatus.data.currentData"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive } from 'vue';
import { Message } from '@arco-design/web-vue';
import { postList, postUpdate, postDelete } from '@/api/admin/post.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import Info from './info.vue';

const getDatas = async () => {
  const { data } = await postList(table.form);
  table.fields = data.fields;
  table.data = data.data;
  table.pagination = {
    total: data.total,
    current: data.current_page || 1,
    pageSize: data.per_page || 15,
  };
};

// 切换状态
const changeStatusFun = (record) => {
  postUpdate({ id: record.id, status: record.status }).then(() => {
    Message.success('更新成功');
  });
};

let postEditStatus = useFormEdit();

const editData = (row) => {
  postEditStatus.updateFormEditStatus(row);
};

const delData = (record) => {
  postDelete({ id: record.id }).then(() => {
    Message.success('删除成功');
    getDatas();
  });
};

// 数据
const table = reactive({
  form: {},
  pagination: { current: 1, pageSize: 15 },
  data: [],
  fields: [],
  ignoreFields: ['operation'],
  columns: [
    {
      dataIndex: 'post_name',
      title: '岗位名称',
      width: 150,
      ellipsis: true,
      tooltip: true,
    },
    {
      dataIndex: 'post_code',
      title: '岗位编码',
      width: 150,
      ellipsis: true,
      tooltip: true,
    },
    {
      dataIndex: 'sort',
      title: '排序',
      width: 80,
    },
    {
      dataIndex: 'remark',
      title: '备注',
      width: 200,
      ellipsis: true,
      tooltip: true,
    },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'create_time', title: '创建时间', width: 180, ellipsis: true },
    { dataIndex: 'update_time', title: '更新时间', width: 180, ellipsis: true },
    {
      dataIndex: 'operation',
      title: '操作',
      width: 80,
      align: 'center',
      fixed: 'right',
      slotName: 'operation',
    },
  ],
});
</script>
