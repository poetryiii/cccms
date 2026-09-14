import { http } from './request'

export type RefreshScope = 'all' | 'menu' | 'perm' | 'cache'

export interface RefreshResult {
  /** 菜单同步结果（读 db/menu.php） */
  menu?: { created: number; updated: number; removed: number }
  /** 按钮节点同步结果（扫描控制器 #[Permission] 注解） */
  perm?: { created: number; skipped: number }
  /** 缓存清理结果 */
  cache?: Record<string, boolean>
}

/**
 * 系统同步 / 清理缓存。
 *
 * 与命令行 `cccms:menu-sync` / `cccms:perm-scan` 共用同一份实现，
 * 只是把入口搬到后台界面，不用再登服务器手动执行。
 */
export function systemRefresh(scope: RefreshScope) {
  return http.post<RefreshResult>('/system/refresh', { scope })
}
