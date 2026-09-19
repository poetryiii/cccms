/** 列表页公共类型 */
import type { PageResult } from '@/api/types'

export type { PageResult }

/** 列头筛选：枚举可选项 */
export interface ArtTableFilterOption {
  label: string
  /** 传给接口的原始值（多选时按逗号拼接） */
  value: string | number
}

/**
 * 列头筛选配置。
 *
 * - `enum`：列头弹层里的复选下拉框（可勾选多个选项，值按逗号拼接后发给接口）；
 * - `text`：列头弹层里的输入框，做模糊查询；
 * - `date`：列头弹层里的日期范围选择器，分别写入起止两个字段（时间列专用）。
 */
export interface ArtTableColumnFilter {
  type: 'enum' | 'text' | 'date'
  /** 枚举可选项（`type='enum'` 时必填） */
  options?: ArtTableFilterOption[]
  /** 写入 query 的字段名，默认取列的 `prop` */
  queryKey?: string
  /** 文本筛选输入框占位文案（`type='text'` 时可选） */
  placeholder?: string
  /** 日期范围起始字段名（`type='date'` 时可选，默认 `start`） */
  startKey?: string
  /** 日期范围结束字段名（`type='date'` 时可选，默认 `end`） */
  endKey?: string
}

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
  /** 锁定可见（如操作列、选择列） */
  lockVisible?: boolean
  showOverflowTooltip?: boolean
  /** 列头筛选配置（配置后该列表头出现筛选入口） */
  filter?: ArtTableColumnFilter
}
