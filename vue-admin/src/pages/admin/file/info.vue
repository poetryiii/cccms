<template>
  <a-modal
    :visible="visible"
    title="修改附件信息"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="file_name" label="文件名称">
        <a-input v-model="form.file_name" placeholder="请输入文件名称" />
      </a-form-item>
      <a-form-item field="file_desc" label="文件备注">
        <a-textarea v-model="form.file_desc" placeholder="请输入文件备注" />
      </a-form-item>
      <a-form-item field="tags" label="标签">
        <a-input-tag v-model="form.tags" placeholder="输入标签后回车" allow-clear />
      </a-form-item>
      <a-form-item field="extract_code" label="提取码">
        <a-input v-model="form.extract_code" placeholder="留空则不设提取码" />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { fileUpdate } from '@/api/admin/file.js';
import { assignObject } from '@/utils/utils.js';

const props = defineProps({
  visible: false,
  data: undefined,
});

const getFormInit = () => ({
  id: undefined,
  tags: [],
  file_name: undefined,
  file_desc: undefined,
  extract_code: undefined,
});

const form = reactive(getFormInit());
const emit = defineEmits(['update:visible', 'done']);

const cancelModal = () => {
  emit('update:visible', false);
};

const okModal = async () => {
  await fileUpdate(form);
  Message.success('修改成功');
  emit('done');
  emit('update:visible', false);
};

watch(
  () => props.visible,
  (visible) => {
    if (visible && props.data) {
      assignObject(form, props.data);
    } else {
      Object.assign(form, getFormInit());
    }
  },
);
</script>
