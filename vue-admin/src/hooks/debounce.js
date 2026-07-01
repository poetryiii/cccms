import { ref, watch } from 'vue';

/**
 * 【底层优化】防抖 Hook：自动处理输入搜索延迟
 * @param {*} value - 要监听的值（通常为ref）
 * @param {number} delay - 防抖延迟（毫秒），默认300
 * @returns {{ debouncedValue: Ref }} - 返回防抖后的响应式值
 *
 * 使用示例：
 *   const keyword = ref('');
 *   const { debouncedValue } = useDebounce(keyword, 500);
 *   watch(debouncedValue, (val) => { fetchData(val); });
 */
export function useDebounce(value, delay = 300) {
  const debouncedValue = ref(value.value);
  let timer = null;

  watch(value, (newVal) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      debouncedValue.value = newVal;
    }, delay);
  });

  return { debouncedValue };
}
