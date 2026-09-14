import type { App, DirectiveBinding } from 'vue'
import { useUserStore } from '@/stores/user'

/**
 * 按钮级权限指令：`v-auth="'cccms:user:save'"`，也支持数组（命中任一即通过）。
 *
 * 用 `display: none` 而不是移除 DOM：元素仍在，权限变化后可恢复，
 * 也避免 Vue 在 patch 时找不到自己创建过的节点。
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
