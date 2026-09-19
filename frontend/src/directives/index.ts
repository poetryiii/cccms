import type { App, DirectiveBinding } from 'vue'
import { useUserStore } from '@/stores/user'

/**
 * 按钮级权限指令：`v-auth="'cccms:user:save'"`，也支持数组（命中任一即通过）。
 *
 * 用 `display: none` 而不是移除 DOM：元素仍在，权限变化后可恢复，
 * 也避免 Vue 在 patch 时找不到自己创建过的节点。
 *
 * 只能挂在「根节点是单个元素」的组件上（如 el-button / el-dropdown / el-upload）。
 * 多根节点组件（如 el-dropdown-item，内部是 ElRovingFocusItem）挂不上，
 * Vue 会告警且隐藏失效，这类场景请改用 `v-if="hasAuth('...')"`。
 */
function authDirective(el: HTMLElement, binding: DirectiveBinding<string | string[] | undefined>): void {
  const user = useUserStore()
  const pass = !binding.value || user.hasAuth(binding.value)
  el.style.display = pass ? '' : 'none'
}

export function setupDirectives(app: App): void {
  app.directive('auth', {
    mounted: authDirective,
    updated: authDirective,
  })
}
