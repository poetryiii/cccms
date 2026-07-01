<template>
  <a-drawer
    :visible="visible"
    :title="'字典数据 - ' + typeName"
    width="50vw"
    :drawer-style="{ minWidth: '400px' }"
    @cancel="emits('update:visible', false)"
  >
    <a-button type="primary" style="margin-bottom: 16px" @click="addData()">
      添加数据
    </a-button>
    <a-table
      :columns="columns"
      :data="dataList"
      :pagination="false"
      row-key="id"
      size="small"
    >
      <template #operation="{ record }">
        <a-button type="text" size="mini" @click="editData(record)">
          <i class="ri-edit-line"></i>
        </a-button>
        <a-popconfirm content="确定删除？" @ok="delData(record)">
          <a-button type="text" size="mini">
            <i
              class="ri-delete-bin-line"
              style="color: rgb(var(--danger-6))"
            ></i>
          </a-button>
        </a-popconfirm>
      </template>
    </a-table>
  </a-drawer>
  <a-modal
    :visible="editVisible"
    :title="editForm.id ? '修改字典数据' : '添加字典数据'"
    @cancel="editVisible = false"
    @ok="saveData"
    width="420px"
  >
    <a-form :model="editForm" layout="vertical">
      <a-form-item>
        <a-input v-model="editForm.label" placeholder="字典标签">
          <template #prefix>标签</template>
        </a-input>
      </a-form-item>
      <a-form-item>
        <a-input v-model="editForm.value" placeholder="字典键值">
          <template #prefix>键值</template>
        </a-input>
      </a-form-item>
      <a-form-item>
        <a-input-number v-model="editForm.sort" placeholder="排序" :min="0" />
      </a-form-item>
      <a-form-item>
        <a-input v-model="editForm.remark" placeholder="备注" />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
import { ref, reactive, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import {
  dictDataList,
  dictDataCreate,
  dictDataUpdate,
  dictDataDelete,
} from '@/api/admin/dict.js';

const props = defineProps({
  visible: Boolean,
  typeId: Number,
  typeName: String,
});
const emits = defineEmits(['update:visible']);
const dataList = ref([]);
const editVisible = ref(false);
const editForm = reactive({
  id: 0,
  type_id: 0,
  label: '',
  value: '',
  sort: 0,
  remark: '',
});
const columns = [
  { dataIndex: 'label', title: '标签', width: 120 },
  { dataIndex: 'value', title: '键值', width: 120 },
  { dataIndex: 'sort', title: '排序', width: 60 },
  { dataIndex: 'remark', title: '备注', width: 150 },
  { dataIndex: 'operation', title: '操作', width: 80, slotName: 'operation' },
];

watch(
  () => props.visible,
  (v) => {
    if (v && props.typeId) loadData();
  },
);
const loadData = async () => {
  const { data } = await dictDataList({ type_id: props.typeId });
  dataList.value = data.data || [];
};
const addData = () => {
  Object.assign(editForm, {
    id: 0,
    type_id: props.typeId,
    label: '',
    value: '',
    sort: 0,
    remark: '',
  });
  editVisible.value = true;
};
const editData = (r) => {
  Object.assign(editForm, { ...r, type_id: props.typeId });
  editVisible.value = true;
};
const saveData = async () => {
  (editForm.id ? dictDataUpdate : dictDataCreate)(editForm).then(() => {
    editVisible.value = false;
    loadData();
  });
};
const delData = (r) => {
  dictDataDelete({ id: r.id }).then(() => {
    Message.success('删除成功');
    loadData();
  });
};
</script>
