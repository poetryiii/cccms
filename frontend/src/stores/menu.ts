import { defineStore } from 'pinia'
import { ref } from 'vue'
import { userTree } from '@/api/menu'
import type { MenuNode } from '@/api/types'

export const useMenuStore = defineStore('menu', () => {
  const menus = ref<MenuNode[]>([])
  const loaded = ref(false)

  async function load(): Promise<MenuNode[]> {
    menus.value = await userTree()
    loaded.value = true
    return menus.value
  }

  function reset(): void {
    menus.value = []
    loaded.value = false
  }

  return { menus, loaded, load, reset }
})
