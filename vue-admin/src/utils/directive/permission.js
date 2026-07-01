import { useUserStore } from '@/stores/admin/user.js';

/**
 * 【底层优化】权限指令：
 * - 使用 display:none 替代 removeChild，支持动态权限变更后恢复显示
 * - updated 钩子支持权限变更时重新判断
 */
const permission = {
  mounted(el, binding) {
    checkPermission(el, binding);
  },
  updated(el, binding) {
    checkPermission(el, binding);
  },
};

function checkPermission(el, binding) {
  let permission = binding.value;
  if (permission) {
    const { nodes } = useUserStore();
    if (!(nodes.indexOf(permission) > -1)) {
      // 没有权限：隐藏元素但不移除，支持后续权限变更恢复
      el.style.display = 'none';
    } else {
      el.style.display = '';
    }
  }
}

export default permission;
