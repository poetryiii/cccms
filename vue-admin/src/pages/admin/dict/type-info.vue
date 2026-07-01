<template>
  <a-drawer
    :visible="visible"
    :title="isUpdate ? '修改字典类型' : '添加字典类型'"
    width="45vw"
    :drawer-style="{ minWidth: '360px' }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="dict_name">
        <a-input v-model="form.dict_name" placeholder="请输入字典名称...">
          <template #prefix>字典名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="dict_type">
        <a-input v-model="form.dict_type" placeholder="请输入字典标识...">
          <template #prefix>字典标识</template>
        </a-input>
      </a-form-item>
      <a-form-item field="remark">
        <a-textarea v-model="form.remark" placeholder="请输入备注..." />
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { dictTypeCreate, dictTypeUpdate } from '@/api/admin/dict.js';

const props = defineProps({
  visible: { type: Boolean, default: false },
  data: { type: Object, default: () => ({}) },
});
const emits = defineEmits(['update:visible', 'done']);
const isUpdate = !!props.data.id;
const form = reactive({ id: 0, dict_name: '', dict_type: '', remark: '' });
watch(
  () => props.data,
  (val) => {
    if (val?.id) {
      form.id = val.id;
      form.dict_name = val.dict_name || '';
      form.dict_type = val.dict_type || '';
      form.remark = val.remark || '';
    } else {
      form.id = 0;
      form.dict_name = '';
      form.dict_type = '';
      form.remark = '';
    }
  },
  { immediate: true },
);
const cancelModal = () => emits('update:visible', false);
const okModal = () => {
  if (!form.dict_name || !form.dict_type) return;
  (isUpdate ? dictTypeUpdate : dictTypeCreate)(form).then(() => {
    emits('update:visible', false);
    emits('done');
  });
};
</script>
