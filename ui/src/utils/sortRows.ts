/**
 * 表格/列表排序：三态（升序 → 降序 → 取消）。
 *
 * 只在视图层对已加载的数据排序（不请求后端），这样各框架、各页面的
 * 行为一致，也不会因为排序把分页/筛选状态打乱。
 */
export type SortDir = 'asc' | 'desc'

export interface SortState {
  key: string
  dir: SortDir
}

/** 空值统一排最后；布尔当 0/1；数字按数值；其余按中文 + 数字感知比较 */
export function compareValues(a: unknown, b: unknown): number {
  const empty = (v: unknown): boolean => v === null || v === undefined || v === ''
  if (empty(a) && empty(b)) return 0
  if (empty(a)) return 1
  if (empty(b)) return -1
  if (typeof a === 'boolean' || typeof b === 'boolean') {
    return (a ? 1 : 0) - (b ? 1 : 0)
  }
  if (typeof a === 'number' && typeof b === 'number') return a - b
  return String(a).localeCompare(String(b), 'zh-Hans-CN', { numeric: true, sensitivity: 'base' })
}

/** state 为 null = 保持原顺序 */
export function sortRows<T>(
  rows: T[],
  state: SortState | null,
  getters: Record<string, (row: T) => unknown>,
): T[] {
  if (!state) return rows
  const get = getters[state.key]
  if (!get) return rows
  const out = [...rows]
  out.sort((x, y) => {
    const r = compareValues(get(x), get(y))
    return state.dir === 'asc' ? r : -r
  })
  return out
}
