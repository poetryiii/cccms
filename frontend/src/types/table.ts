/** 列表页公共类型 */
import type { PageResult } from '@/api/types'

export type { PageResult }

export interface ArtTableColumn {
  /** 字段名 */
  prop: string
  /** 列标题 */
  label: string
  width?: number | string
  minWidth?: number | string
  fixed?: boolean | 'left' | 'right'
  align?: 'left' | 'center' | 'right'
  /** 传 'custom' 需自行监听 sort-change */
  sortable?: boolean | 'custom'
  /** 具名插槽名：<template #xxx="{ row, value }">，不写则直接显示字段值 */
  slot?: string
  /** 默认是否隐藏（用户可在列设置里打开） */
  defaultHidden?: boolean
  /** 锁定可见（如操作列、选择列） */
  lockVisible?: boolean
  showOverflowTooltip?: boolean
}
