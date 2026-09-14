<template>
  <header class="header">
    <div class="header-left">
      <el-tooltip :content="collapsed ? '展开菜单' : '折叠菜单'" placement="bottom">
        <el-button text circle @click="toggleCollapse">
          <el-icon :size="18">
            <Fold v-if="!collapsed" />
            <Expand v-else />
          </el-icon>
        </el-button>
      </el-tooltip>

      <el-breadcrumb class="header-breadcrumb" separator="/">
        <el-breadcrumb-item :to="{ path: HOME_PATH }">首页</el-breadcrumb-item>
        <el-breadcrumb-item v-for="c in crumbs" :key="c.path">{{ c.title }}</el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <div class="header-right">
      <!-- 关闭多标签页时标签栏不存在，刷新入口回落到顶栏 -->
      <el-tooltip v-if="!appStore.tagsView" content="刷新当前页" placement="bottom">
        <el-button text circle @click="refreshPage">
          <el-icon :size="17"><RefreshRight /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 明暗切换已并入「主题设置」抽屉里的「主题模式」，这里不再重复放一个图标 -->
      <el-tooltip content="主题设置" placement="bottom">
        <el-button text circle @click="settingsVisible = true">
          <el-icon :size="17"><Setting /></el-icon>
        </el-button>
      </el-tooltip>

      <el-tooltip content="全屏" placement="bottom">
        <el-button text circle @click="toggleFullscreen">
          <el-icon :size="17"><FullScreen /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 系统同步 / 清理缓存：等价于 menu-sync + perm-scan + 清缓存，不用再登服务器执行 -->
      <el-tooltip content="系统同步 / 清理缓存" placement="bottom">
        <el-dropdown
          v-auth="'cccms:config:refresh'"
          trigger="click"
          @command="onRefreshCommand"
        >
          <el-button text circle :loading="refreshing">
            <el-icon :size="17"><Refresh /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="all">全部刷新（菜单 + 按钮 + 缓存）</el-dropdown-item>
              <el-dropdown-item command="menu" divided>同步菜单节点</el-dropdown-item>
              <el-dropdown-item command="perm">同步按钮权限节点</el-dropdown-item>
              <el-dropdown-item command="cache">清理缓存</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-tooltip>

      <el-dropdown trigger="click" @command="onUserCommand">
        <div class="header-user">
          <el-avatar :size="28" class="header-user-avatar" :src="userStore.profile?.avatar || undefined">
            {{ avatarText }}
          </el-avatar>
          <span class="header-user-name">{{ userStore.nickname || '未登录' }}</span>
          <el-icon :size="12"><ArrowDown /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item disabled>
              <span class="header-user-role">
                {{ userStore.superAdmin ? '超级管理员' : (userStore.profile?.roles || []).join(' / ') || '普通用户' }}
              </span>
            </el-dropdown-item>
            <el-dropdown-item command="profile" divided>
              <el-icon><User /></el-icon>
              个人中心
            </el-dropdown-item>
            <el-dropdown-item command="logout">
              <el-icon><SwitchButton /></el-icon>
              退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>

    <ArtSettingsDrawer v-model="settingsVisible" />
  </header>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, Expand, Fold, FullScreen, Refresh, RefreshRight, Setting, SwitchButton, User } from '@element-plus/icons-vue'
import ArtSettingsDrawer from '@/components/core/ArtSettingsDrawer.vue'
import { reloadMenus, resetAfterLogout } from '@/router'
import { systemRefresh, type RefreshResult, type RefreshScope } from '@/api/system'
import { useAppStore } from '@/stores/app'
import { HOME_PATH, useWorktabStore } from '@/stores/worktab'
import { useUserStore } from '@/stores/user'

const props = defineProps<{ collapsed: boolean }>()
const emit = defineEmits<{ 'update:collapsed': [value: boolean] }>()

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const worktab = useWorktabStore()
const userStore = useUserStore()

const settingsVisible = ref(false)

/** 面包屑：动态路由都挂在 layout 下，故跳过第一层 */
const crumbs = computed(() =>
  route.matched
    .slice(1)
    .filter((r) => r.meta?.title)
    .map((r) => ({ path: r.path, title: String(r.meta.title) })),
)

const avatarText = computed(() => (userStore.nickname || 'U').charAt(0).toUpperCase())

function toggleCollapse(): void {
  emit('update:collapsed', !props.collapsed)
}

/** 仅在「关闭多标签页」时使用：刷新入口正常在标签栏右侧 */
function refreshPage(): void {
  void worktab.refreshActive()
}

async function toggleFullscreen(): Promise<void> {
  if (document.fullscreenElement) {
    await document.exitFullscreen()
  } else {
    await document.documentElement.requestFullscreen()
  }
}

/* ---- 系统同步 / 清理缓存 ---- */
const refreshing = ref(false)

const REFRESH_LABELS: Record<string, string> = {
  all: '全部刷新',
  menu: '同步菜单节点',
  perm: '同步按钮权限节点',
  cache: '清理缓存',
}

function describeRefresh(result: RefreshResult): string {
  const parts: string[] = []
  if (result.menu) {
    parts.push(`菜单 新增 ${result.menu.created} / 更新 ${result.menu.updated} / 清理 ${result.menu.removed}`)
  }
  if (result.perm) {
    parts.push(`按钮 新增 ${result.perm.created} / 跳过 ${result.perm.skipped}`)
  }
  if (result.cache) {
    parts.push('缓存已清理')
  }
  return parts.join('；') || '无变更'
}

async function onRefreshCommand(command: string | number | object): Promise<void> {
  const scope = String(command) as RefreshScope

  if (scope === 'all') {
    try {
      await ElMessageBox.confirm(
        '将依次执行：同步菜单节点 → 同步按钮权限节点 → 清理缓存。确定继续？',
        '全部刷新',
        { type: 'warning' },
      )
    } catch {
      return
    }
  }

  refreshing.value = true
  try {
    const result = await systemRefresh(scope)
    ElMessage.success(`${REFRESH_LABELS[scope] ?? '刷新'}完成：${describeRefresh(result)}`)

    // 菜单 / 按钮节点变了 → 重挂动态路由 + 刷新权限，侧边栏立即生效
    if (scope === 'menu' || scope === 'perm' || scope === 'all') {
      await reloadMenus()
    }
  } finally {
    refreshing.value = false
  }
}

async function onUserCommand(command: string | number | object): Promise<void> {
  if (command === 'profile') {
    router.push('/profile')
    return
  }
  if (command !== 'logout') {
    return
  }
  try {
    await ElMessageBox.confirm('确定要退出当前账号吗？', '退出登录', {
      type: 'warning',
      confirmButtonText: '退出',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  await userStore.logout()
  resetAfterLogout()
  router.push('/login')
}
</script>

<style scoped>
.header {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  height: var(--art-header-height);
  padding: 0 12px 0 8px;
  background: var(--art-header-bg);
  border-bottom: 1px solid var(--art-card-border);
}

.header-left,
.header-right {
  display: flex;
  gap: 4px;
  align-items: center;
}

.header-breadcrumb {
  margin-left: 6px;
  font-size: 13px;
}

.header-user {
  display: flex;
  gap: 8px;
  align-items: center;
  height: 36px;
  padding: 0 10px;
  margin-left: 4px;
  cursor: pointer;
  border-radius: calc(var(--art-radius) - 2px);
  outline: none;
  transition: background 0.15s ease;
}

.header-user:hover {
  background: var(--art-hover-bg);
}

.header-user-avatar {
  font-size: 13px;
  background: var(--art-primary);
}

.header-user-name {
  max-width: 120px;
  overflow: hidden;
  font-size: 14px;
  color: var(--art-main);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.header-user-role {
  font-size: 12px;
  color: var(--art-muted);
}
</style>
