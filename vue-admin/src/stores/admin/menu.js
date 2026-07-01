import { defineStore } from 'pinia';

export const useMenuStore = defineStore('menu', {
  state: () => ({
    // 完整菜单树
    menus: undefined,
    // 当前菜单（去掉多应用包装后，直接等于 menus）
    currentMenus: undefined,
    // 选中的菜单
    selectedKeys: undefined,
  }),
  getters: {
    getSelectedKeys() {
      return Number(this.selectedKeys);
    },
  },
  actions: {
    /**
     * 获取菜单
     * @param {*} val id|node
     * @param {*} menus
     * @returns
     */
    getMenu(val, menus = {}) {
      if (Object.keys(menus).length == 0) {
        menus = this.menus;
      }
      for (let i in menus) {
        if (menus[i].id == val || menus[i].node == val) {
          return menus[i];
        }
        if (menus[i].children) {
          let res = this.getMenu(val, menus[i].children);
          if (res !== undefined) {
            return res;
          }
        }
      }
    },
    setMenus(menus = {}) {
      this.menus = menus;
      this.currentMenus = menus;
    },
  },
  persist: true,
});
