import instance from './request'

/**
 * 自动升级（上游框架更新）。
 *
 * 直接用 axios 实例而不是 `http` 包装：升级要 clone / fetch 上游仓库，
 * 首次可能耗时数十秒，需要单独放宽超时（默认 15s 会直接超时）。
 */

/** 升级操作统一放宽到 3 分钟 */
const SLOW = { timeout: 180000 }

/** 上游同步源（Gitee 国内镜像 / GitHub 国外） */
export interface UpgradeSource {
  key: string
  label: string
  url: string
}

/** 当前版本（来自基线状态文件） */
export interface UpgradeCurrent {
  initialized: boolean
  /** 基线版本（分支 / tag） */
  base: string
  commit: string
  synced_at: string
  files: number
}

export interface UpgradeOverview {
  enabled: boolean
  git_available: boolean
  initialized: boolean
  current: UpgradeCurrent
  sources: UpgradeSource[]
  default_source: string
  track: string
  default_base: string
  backup_dir: string
}

export interface UpgradeCommit {
  hash: string
  short: string
  author: string
  date: string
  message: string
}

/** 分类计数：new / safe / local / deleted / conflict / removed / same */
export type UpgradeSummary = Record<string, number>

/** 变更文件（只含非 same 的，已按关注度排序） */
export interface UpgradeFile {
  path: string
  kind: string
  kind_label: string
  added: number
  deleted: number
  binary: boolean
}

export interface UpgradePlan {
  source: UpgradeSource
  ref: string
  commit: string
  base: string
  base_commit: string
  summary: UpgradeSummary
  files: UpgradeFile[]
  commits: UpgradeCommit[]
  lines: { added: number; deleted: number }
  /** 本地独有文件数（业务插件，不会被改动） */
  local_only: number
  /** 可直接覆盖的数量（可安全覆盖 + 上游新增） */
  upgradable: number
  /** 需要人工合并的冲突数 */
  pending: number
  has_update: boolean
}

export interface UpgradeInitResult {
  source: UpgradeSource
  ref: string
  commit: string
  fallback: string
  total: number
  modified: number
  missing: number
  local_only: number
}

export interface UpgradeRunResult {
  ref: string
  commit: string
  written: number
  removed: number
  backed: number
  skipped: string[]
  backup_dir: string
  report: string
  maintenance: unknown
  maintenance_error: string
  /** 有文件变动时需要 reload / 重启服务才会生效 */
  need_reload: boolean
}

export function upgradeOverview() {
  return instance.get('/upgrade', SLOW) as unknown as Promise<UpgradeOverview>
}

export function upgradeCheck(source: string, ref?: string) {
  return instance.get('/upgrade/check', {
    params: { source, ref: ref || undefined },
    ...SLOW,
  }) as unknown as Promise<UpgradePlan>
}

export function upgradeTags(source: string) {
  return instance.get('/upgrade/tags', {
    params: { source },
    ...SLOW,
  }) as unknown as Promise<{ tags: string[] }>
}

export function upgradeInit(source: string, ref?: string) {
  return instance.post(
    '/upgrade/init',
    { source, ref: ref || undefined },
    SLOW,
  ) as unknown as Promise<UpgradeInitResult>
}

export function upgradeRun(payload: { source: string; ref?: string; force?: boolean; prune?: boolean }) {
  return instance.post('/upgrade/run', payload, SLOW) as unknown as Promise<UpgradeRunResult>
}
