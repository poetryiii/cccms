<template>
  <a-form :model="dynamicData" layout="vertical">
    <template v-for="(data, index) in dynamicData" :key="data.name || index">
      <a-form-item v-if="isVisible(data)" :label="data.label">
        <a-input v-if="data.type === 'input'" v-model="data['value']" :placeholder="data.placeholder" />
        <a-input-password v-else-if="data.type === 'password'" v-model="data['value']" :placeholder="data.placeholder" allow-clear />
        <a-input-number v-else-if="data.type === 'input-number'" v-model="data['value']" :placeholder="data.placeholder" />
        <a-textarea v-else-if="data.type === 'textarea'" v-model="data['value']" :placeholder="data.placeholder" allow-clear />
        <a-select v-else-if="data.type === 'select'" v-model="data['value']" :placeholder="data.placeholder" @change="onFieldChange(data)">
          <a-option v-for="(d, i) in data.options" :value="d.value" :label="d.label" />
        </a-select>
        <a-select v-else-if="data.type === 'multiple-select'" v-model="data['value']" :placeholder="data.placeholder" multiple>
          <a-option v-for="(d, i) in data.options" :value="d.value" :label="d.label" />
        </a-select>
        <a-date-picker v-else-if="data.type === 'date-picker'" v-model="data['value']" />
        <a-range-picker showTime v-else-if="data.type === 'date-range-picker'" v-model="data['value']" />
        <a-switch v-else-if="data.type === 'switch'" v-model="data['value']" :checked-value="data.options['checked']" :unchecked-value="data.options['unchecked']" />
        <template #extra><div>{{ data.description }}</div></template>
      </a-form-item>
    </template>
  </a-form>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({ data: {} });
const dynamicData = ref([]);
const refreshKey = ref(0);

// 判断是否需要显示：如果有 condition，检查关联字段的值
const isVisible = (data) => {
  if (!data.condition) return true;
  const cond = typeof data.condition === 'string' ? JSON.parse(data.condition) : data.condition;
  if (!cond || !cond.field) return true;
  const target = (dynamicData.value || []).find(d => d.name === cond.field);
  if (!target) return true;
  if (cond.not !== undefined) return String(target.value) !== String(cond.not);
  if (cond.eq !== undefined) return String(target.value) === String(cond.eq);
  return true;
};

const onFieldChange = (data) => {
  // 触发字段变更后重新渲染，使条件字段重新计算可见性
  refreshKey.value++;
};

// 监听数据变化，解析 condition JSON
watch(() => props.data, (val) => {
  if (!val) { dynamicData.value = []; return; }
  dynamicData.value = (Array.isArray(val) ? val : []).map(item => {
    if (item.configure && typeof item.configure === 'string') {
      try { const cfg = JSON.parse(item.configure); item.condition = cfg.condition; } catch(e) {}
    }
    return item;
  });
  refreshKey.value++;
}, { immediate: true, deep: true });

const getDynamicDatas = () => props.data;

defineExpose({ getDynamicDatas });
</script>
