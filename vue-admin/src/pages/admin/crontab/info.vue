<template>
  <a-drawer
    :visible="visible"
    :title="isUpdate ? '修改任务' : '添加任务'"
    width="45vw"
    :drawer-style="{ minWidth: '360px' }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="title">
        <a-input v-model="form.title" placeholder="请输入任务名称...">
          <template #prefix>任务名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="command">
        <a-input
          v-model="form.command"
          placeholder="请输入命令，如 ClassName@method..."
        >
          <template #prefix>执行命令</template>
        </a-input>
      </a-form-item>
      <a-form-item field="rule">
        <a-input v-model="form.rule" placeholder="Cron表达式，如 */5 * * * *">
          <template #prefix>Cron表达式</template>
        </a-input>
      </a-form-item>
      <a-form-item field="params">
        <a-input v-model="form.params" placeholder="任务参数（可选）">
          <template #prefix>参数</template>
        </a-input>
      </a-form-item>
      <a-form-item field="type">
        <a-radio-group v-model="form.type">
          <a-radio :value="1">单次</a-radio>
          <a-radio :value="2">循环</a-radio>
        </a-radio-group>
      </a-form-item>
      <a-form-item field="max_retry">
        <a-input-number
          v-model="form.max_retry"
          placeholder="失败重试次数"
          :min="0"
        />
        <template #prefix>重试次数</template>
      </a-form-item>
      <a-form-item field="remark">
        <a-textarea v-model="form.remark" placeholder="备注" />
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { crontabCreate, crontabUpdate } from '@/api/admin/crontab.js';
const props = defineProps({
  visible: { type: Boolean, default: false },
  data: { type: Object, default: () => ({}) },
});
const emits = defineEmits(['update:visible', 'done']);
const isUpdate = !!props.data.id;
const form = reactive({
  id: 0,
  title: '',
  command: '',
  rule: '',
  params: '',
  type: 2,
  max_retry: 0,
  remark: '',
});
watch(
  () => props.data,
  (v) => {
    if (v?.id) {
      Object.assign(form, {
        id: v.id,
        title: v.title || '',
        command: v.command || '',
        rule: v.rule || '',
        params: v.params || '',
        type: v.type || 2,
        max_retry: v.max_retry || 0,
        remark: v.remark || '',
      });
    } else {
      form.id = 0;
      form.title = '';
      form.command = '';
      form.rule = '';
      form.params = '';
      form.type = 2;
      form.max_retry = 0;
      form.remark = '';
    }
  },
  { immediate: true },
);
const cancelModal = () => emits('update:visible', false);
const okModal = () => {
  if (!form.title || !form.command || !form.rule) return;
  (isUpdate ? crontabUpdate : crontabCreate)(form).then(() => {
    emits('update:visible', false);
    emits('done');
  });
};
</script>
