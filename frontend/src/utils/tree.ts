/** 枚举多选匹配：空条件放行，否则按逗号串匹配 */
export function matchEnum(value: unknown, raw: string): boolean {
  return raw === '' || raw.split(',').includes(String(value))
}

/**
 * 树形过滤：命中节点整棵子树原样保留；未命中但子孙命中的节点保留自身，children 换成过滤结果。
 */
export function filterTree<T extends { children?: T[] }>(nodes: T[], match: (node: T) => boolean): T[] {
  const out: T[] = []
  for (const node of nodes) {
    if (match(node)) {
      out.push(node)
      continue
    }
    const children = node.children?.length ? filterTree(node.children, match) : []
    if (children.length) {
      out.push({ ...node, children })
    }
  }
  return out
}
