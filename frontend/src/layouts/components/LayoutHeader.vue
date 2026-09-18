<template>
  <header class="header">
    <div class="header-left">
      <el-tooltip :content="collapsed ? t('layout.expandMenu') : t('layout.collapseMenu')" placement="bottom">
        <el-button text circle @click="toggleCollapse">
          <el-icon :size="18">
            <Fold v-if="!collapsed" />
            <Expand v-else />
          </el-icon>
        </el-button>
      </el-tooltip>

      <el-breadcrumb class="header-breadcrumb" separator="/">
        <el-breadcrumb-item :to="{ path: HOME_PATH }">{{ t('layout.home') }}</el-breadcrumb-item>
        <!-- 中间层级可点击跳转（最后一级是当前页，不给链接） -->
        <el-breadcrumb-item
          v-for="(c, index) in crumbs"
          :key="c.path"
          :to="index < crumbs.length - 1 && c.path ? { path: c.path } : undefined"
        >
          {{ c.title }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <div class="header-right">
      <!-- 快捷导航：搜索菜单并跳转（Ctrl/⌘ + K） -->
      <el-tooltip :content="t('layout.quickNavHotkey')" placement="bottom">
        <el-button text circle @click="openSearch">
          <el-icon :size="17"><Search /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 我的消息：未读角标 + 抽屉 -->
      <el-tooltip :content="t('layout.notice')" placement="bottom">
        <el-badge :value="noticeStore.unread" :max="99" :hidden="noticeStore.unread === 0" class="header-badge">
          <el-button text circle @click="openNoticeDrawer">
            <el-icon :size="17"><Bell /></el-icon>
          </el-button>
        </el-badge>
      </el-tooltip>

      <!-- 关闭多标签页时标签栏不存在，刷新入口回落到顶栏 -->
      <el-tooltip v-if="!appStore.tagsView" :content="t('layout.refreshPage')" placement="bottom">
        <el-button text circle @click="refreshPage">
          <el-icon :size="17"><RefreshRight /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 明暗切换已并入「主题设置」抽屉里的「主题模式」，这里不再重复放一个图标 -->
      <el-tooltip :content="t('layout.themeSetting')" placement="bottom">
        <el-button text circle @click="settingsVisible = true">
          <el-icon :size="17"><Setting /></el-icon>
        </el-button>
      </el-tooltip>

      <el-tooltip :content="t('layout.fullscreen')" placement="bottom">
        <el-button text circle @click="toggleFullscreen">
          <el-icon :size="17"><FullScreen /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 系统同步 / 清理缓存：等价于 menu-sync + perm-scan + 清缓存，不用再登服务器执行 -->
      <el-tooltip :content="t('layout.syncCache')" placement="bottom">
        <el-dropdown v-auth="'cccms:config:refresh'" trigger="click" @command="onRefreshCommand">
          <el-button text circle :loading="refreshing">
            <el-icon :size="17"><Refresh /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="all">{{ t('layout.syncAll') }}</el-dropdown-item>
              <el-dropdown-item command="menu" divided>{{ t('layout.syncMenu') }}</el-dropdown-item>
              <el-dropdown-item command="perm">{{ t('layout.syncPerm') }}</el-dropdown-item>
              <el-dropdown-item command="cache">{{ t('layout.syncCacheItem') }}</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-tooltip>

      <el-dropdown trigger="click" @command="onUserCommand">
        <div class="header-user">
          <el-avatar :size="28" class="header-user-avatar" :src="userStore.profile?.avatar || undefined">
            {{ avatarText }}
          </el-avatar>
          <span class="header-user-name">{{ userStore.nickname || t('layout.notLoggedIn') }}</span>
          <el-icon :size="12"><ArrowDown /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item disabled>
              <span class="header-user-role">
                {{
                  userStore.superAdmin
                    ? t('layout.superAdmin')
                    : (userStore.profile?.roles || []).join(' / ') || t('layout.normalUser')
                }}
              </span>
            </el-dropdown-item>
            <el-dropdown-item command="profile" divided>
              <el-icon><User /></el-icon>
              {{ t('layout.profile') }}
            </el-dropdown-item>
            <el-dropdown-item command="logout">
              <el-icon><SwitchButton /></el-icon>
              {{ t('layout.logout') }}
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>

    <ArtSettingsDrawer v-model="settingsVisible" />

    <!-- 快捷导航 -->
    <el-dialog
      v-model="searchVisible"
      :title="t('layout.quickNav')"
      width="520px"
      class="header-search-dialog"
      append-to-body
    >
      <el-input
        ref="searchInputRef"
        v-model="keyword"
        :placeholder="t('layout.searchPlaceholder')"
        clearable
        :prefix-icon="Search"
        @keydown.enter="gotoFirst"
      />
      <div class="header-search-list">
        <el-empty v-if="searchResult.length === 0" :description="t('layout.searchEmpty')" :image-size="60" />
        <div
          v-for="item in searchResult"
          :key="item.path"
          class="header-search-item"
          :class="{ 'is-active': item.path === activeResultPath }"
          @click="goto(item.path)"
        >
          <ArtIcon :name="item.icon" />
          <span class="header-search-title">{{ item.title }}</span>
          <span class="header-search-path">{{ item.path }}</span>
        </div>
      </div>
    </el-dialog>

    <!-- 我的消息 -->
    <el-drawer v-model="noticeVisible" size="420px" append-to-body>
      <template #header>
        <div class="notice-head">
          <span class="notice-head-title">{{ t('layout.notice') }}</span>
          <el-button v-if="noticeStore.unread > 0" link type="primary" size="small" @click="markAllRead">
            {{ t('layout.noticeAllRead') }}
          </el-button>
        </div>
      </template>

      <div v-loading="noticeStore.loading" class="notice-list">
        <el-empty v-if="noticeStore.items.length === 0" :description="t('layout.noticeEmpty')" :image-size="70" />
        <div
          v-for="item in noticeStore.items"
          :key="item.id"
          class="notice-item"
          :class="{ 'is-read': item.is_read }"
          @click="openNotice(item)"
        >
          <div class="notice-item-title">
            <span class="notice-dot" :class="{ 'is-hidden': item.is_read }" />
            <span class="notice-text">{{ item.title }}</span>
            <el-tag v-if="item.level === 2" type="danger" size="small" effect="plain">
              {{ t('layout.noticeImportant') }}
            </el-tag>
            <el-tag v-else-if="item.type === 2" type="primary" size="small" effect="plain">
              {{ t('layout.noticeAnnouncement') }}
            </el-tag>
          </div>
          <div class="notice-item-time">{{ item.publish_at || item.create_time }}</div>
        </div>
      </div>
    </el-drawer>

    <!-- 消息详情 -->
    <el-dialog
      v-model="noticeDetailVisible"
      :title="currentNotice?.title || t('layout.noticeDetail')"
      width="640px"
      append-to-body
    >
      <div class="notice-detail-meta">
        <el-tag size="small" effect="plain">
          {{ currentNotice?.type === 2 ? t('layout.noticeAnnouncement') : t('layout.noticeNotification') }}
        </el-tag>
        <span>{{ currentNotice?.publish_at || currentNotice?.create_time }}</span>
      </div>
      <div class="notice-detail-content">{{ currentNotice?.content }}</div>
    </el-dialog>
  </header>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  ArrowDown,
  Bell,
  Expand,
  Fold,
  FullScreen,
  Refresh,
  RefreshRight,
  Search,
  Setting,
  SwitchButton,
  User,
} from '@element-plus/icons-vue'
import ArtSettingsDrawer from '@/components/core/ArtSettingsDrawer.vue'
import ArtIcon from '@/components/core/ArtIcon.vue'
import { reloadMenus, resetAfterLogout } from '@/router'
import { systemRefresh, type RefreshResult, type RefreshScope } from '@/api/system'
import type { MenuNode } from '@/api/types'
import type { NoticeRow } from '@/api/notice'
import { useAppStore } from '@/stores/app'
import { useMenuStore } from '@/stores/menu'
import { useNoticeStore } from '@/stores/notice'
import { HOME_PATH, useWorktabStore } from '@/stores/worktab'
import { useUserStore } from '@/stores/user'

const props = defineProps<{ collapsed: boolean }>()
const emit = defineEmits<{ 'update:collapsed': [value: boolean] }>()

const { t } = useI18n({ useScope: 'global' })

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const menuStore = useMenuStore()
const noticeStore = useNoticeStore()
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

/* ---- 快捷导航 ---- */
interface FlatMenu {
  title: string
  path: string
  icon: string
}

const searchVisible = ref(false)
const keyword = ref('')
const searchInputRef = ref<{ focus: () => void } | null>(null)

/** 只取可跳转的菜单（type=2 且 path 非空），目录与按钮不参与 */
function flattenMenus(nodes: MenuNode[]): FlatMenu[] {
  const out: FlatMenu[] = []
  const walk = (list: MenuNode[]): void => {
    for (const node of list) {
      if (node.type === 2 && node.path) {
        out.push({ title: node.title, path: node.path, icon: node.icon })
      }
      if (node.children?.length) {
        walk(node.children)
      }
    }
  }
  walk(nodes)
  return out
}

const flatMenus = computed(() => flattenMenus(menuStore.menus))

const searchResult = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) {
    return flatMenus.value.slice(0, 12)
  }
  return flatMenus.value
    .filter((m) => m.title.toLowerCase().includes(kw) || m.path.toLowerCase().includes(kw))
    .slice(0, 20)
})

const activeResultPath = computed(() => searchResult.value[0]?.path ?? '')

function openSearch(): void {
  keyword.value = ''
  searchVisible.value = true
  void nextTick(() => searchInputRef.value?.focus())
}

function goto(path: string): void {
  if (!path) {
    return
  }
  searchVisible.value = false
  void router.push(path)
}

function gotoFirst(): void {
  if (activeResultPath.value) {
    goto(activeResultPath.value)
  }
}

function onHotkey(event: KeyboardEvent): void {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    openSearch()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onHotkey)
  void noticeStore.refreshUnread()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onHotkey)
})

/* ---- 我的消息 ---- */
const noticeVisible = ref(false)
const noticeDetailVisible = ref(false)
const currentNotice = ref<NoticeRow | null>(null)

function openNoticeDrawer(): void {
  noticeVisible.value = true
  void noticeStore.load({ page: 1, limit: 20 })
}

async function openNotice(item: NoticeRow): Promise<void> {
  currentNotice.value = item
  noticeDetailVisible.value = true
  try {
    await noticeStore.markRead(item.id)
  } catch {
    // 标记已读失败不影响阅读
  }
}

async function markAllRead(): Promise<void> {
  try {
    await noticeStore.markAllRead()
    ElMessage.success(t('layout.noticeAllReadSuccess'))
  } catch {
    // 拦截器已提示
  }
}

/* ---- 系统同步 / 清理缓存 ---- */
const refreshing = ref(false)

function refreshLabel(scope: string): string {
  const labels: Record<string, string> = {
    all: t('layout.syncAll'),
    menu: t('layout.syncMenu'),
    perm: t('layout.syncPerm'),
    cache: t('layout.syncCacheItem'),
  }
  return labels[scope] ?? t('common.refresh')
}

function describeRefresh(result: RefreshResult): string {
  const parts: string[] = []
  if (result.menu) {
    parts.push(
      t('layout.refreshMenu', {
        created: result.menu.created,
        updated: result.menu.updated,
        removed: result.menu.removed,
      }),
    )
  }
  if (result.perm) {
    parts.push(t('layout.refreshPerm', { created: result.perm.created, skipped: result.perm.skipped }))
  }
  if (result.cache) {
    parts.push(t('layout.refreshCacheDone'))
  }
  return parts.join('；') || t('layout.refreshNoChange')
}

async function onRefreshCommand(command: string | number | object): Promise<void> {
  const scope = String(command) as RefreshScope

  if (scope === 'all') {
    try {
      await ElMessageBox.confirm(t('layout.refreshAllConfirm'), t('layout.refreshAllTitle'), {
        type: 'warning',
      })
    } catch {
      return
    }
  }

  refreshing.value = true
  try {
    const result = await systemRefresh(scope)
    ElMessage.success(t('layout.refreshDone', { name: refreshLabel(scope), detail: describeRefresh(result) }))

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
    await ElMessageBox.confirm(t('layout.logoutConfirm'), t('layout.logout'), {
      type: 'warning',
      confirmButtonText: t('layout.logoutConfirmBtn'),
      cancelButtonText: t('common.cancel'),
    })
  } catch {
    return
  }
  await userStore.logout()
  noticeStore.reset()
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

.header-badge {
  display: inline-flex;
  align-items: center;
  line-height: 1;
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

/* ---- 快捷导航 ---- */
.header-search-list {
  max-height: 320px;
  margin-top: 10px;
  overflow-y: auto;
}

.header-search-item {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  cursor: pointer;
  border-radius: calc(var(--art-radius) - 4px);
}

.header-search-item:hover {
  background: var(--art-hover-bg);
}

.header-search-title {
  font-size: 14px;
  color: var(--art-main);
}

.header-search-path {
  margin-left: auto;
  font-size: 12px;
  color: var(--art-muted);
}

/* ---- 我的消息 ---- */
.notice-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.notice-head-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--art-main);
}

.notice-list {
  min-height: 120px;
}

.notice-item {
  padding: 10px 4px;
  cursor: pointer;
  border-bottom: 1px solid var(--art-card-border);
}

.notice-item:hover {
  background: var(--art-hover-bg);
}

.notice-item.is-read .notice-text {
  color: var(--art-sub);
}

.notice-item-title {
  display: flex;
  gap: 6px;
  align-items: center;
  font-size: 14px;
  color: var(--art-main);
}

.notice-text {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-dot {
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  background: var(--art-danger);
  border-radius: 50%;
}

.notice-dot.is-hidden {
  visibility: hidden;
}

.notice-item-time {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-muted);
}

.notice-detail-meta {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
  font-size: 12px;
  color: var(--art-muted);
}

.notice-detail-content {
  font-size: 14px;
  line-height: 1.7;
  color: var(--art-main);
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
