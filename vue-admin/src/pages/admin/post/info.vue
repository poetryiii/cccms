<template>
  <a-drawer
    :visible="visible"
    :title="isUpdate ? '修改岗位' : '添加岗位'"
    width="45vw"
    :drawer-style="{
      minWidth: '360px',
    }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="post_name">
        <a-input v-model="form.post_name" placeholder="请输入岗位名称...">
          <template #prefix>岗位名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="post_code">
        <a-input v-model="form.post_code" placeholder="请输入岗位编码...">
          <template #prefix>岗位编码</template>
        </a-input>
      </a-form-item>
      <a-form-item field="sort">
        <a-input-number
          v-model="form.sort"
          placeholder="请输入排序..."
          :min="0"
        >
          <template #prefix>排序</template>
        </a-input-number>
      </a-form-item>
      <a-form-item field="remark">
        <a-textarea v-model="form.remark" placeholder="请输入岗位备注..." />
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { postCreate, postUpdate } from '@/api/admin/post.js';

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  data: {
    type: Object,
    default: {},
  },
});

const emits = defineEmits(['update:visible', 'done']);

const isUpdate = !!props.data.id;

const form = reactive({
  id: 0,
  post_name: '',
  post_code: '',
  sort: 0,
  remark: '',
});

watch(
  () => props.data,
  (val) => {
    if (val && val.id) {
      form.id = val.id;
      form.post_name = val.post_name || '';
      form.post_code = val.post_code || '';
      form.sort = val.sort || 0;
      form.remark = val.remark || '';
    } else {
      form.id = 0;
      form.post_name = '';
      form.post_code = '';
      form.sort = 0;
      form.remark = '';
    }
  },
  { immediate: true },
);

const cancelModal = () => {
  emits('update:visible', false);
};

const okModal = () => {
  if (!form.post_name) {
    return;
  }
  const api = isUpdate ? postUpdate : postCreate;
  api(form).then(() => {
    emits('update:visible', false);
    emits('done');
  });
};
</script>
