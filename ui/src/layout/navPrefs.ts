/**
 * 侧边栏排版偏好。
 * 存 localStorage：跟浏览器走，不写服务端；换设备/清缓存会回到默认。
 * 这样不用为「排序」再开一个后端接口，也不影响其他账号。
 */

export type NavSortMode = 'rank' | 'len' | 'custom'

export interface NavPrefs {
  mode: NavSortMode
  /** 组顺序（组名数组），只在 custom 模式生效 */
  groupOrder: string[]
  /** 组内顺序：组名 -> path[]，只在 custom 模式生效 */
  itemOrder: Record<string, string[]>
  /** 隐藏的 path（所有模式都生效） */
  hidden: string[]
  /** 用户把某一项拖到了哪个组（path -> 组名）；没覆盖的用路由默认组 */
  itemGroup: Record<string, string>
  /** 用户新建的分组（可能暂时是空组） */
  customGroups: string[]
}

const KEY = 'hyper-nav-prefs'

export function defaultNavPrefs(): NavPrefs {
  return { mode: 'rank', groupOrder: [], itemOrder: {}, hidden: [], itemGroup: {}, customGroups: [] }
}

function strings(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((x): x is string => typeof x === 'string') : []
}

export function loadNavPrefs(): NavPrefs {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return defaultNavPrefs()
    const parsed = JSON.parse(raw) as Partial<NavPrefs>
    const itemOrder: Record<string, string[]> = {}
    if (parsed.itemOrder && typeof parsed.itemOrder === 'object') {
      for (const [group, list] of Object.entries(parsed.itemOrder)) itemOrder[group] = strings(list)
    }
    const itemGroup: Record<string, string> = {}
    if (parsed.itemGroup && typeof parsed.itemGroup === 'object') {
      for (const [path, group] of Object.entries(parsed.itemGroup)) {
        if (typeof group === 'string' && group !== '') itemGroup[path] = group
      }
    }
    return {
      mode: parsed.mode === 'len' || parsed.mode === 'custom' ? parsed.mode : 'rank',
      groupOrder: strings(parsed.groupOrder),
      itemOrder,
      hidden: strings(parsed.hidden),
      itemGroup,
      customGroups: strings(parsed.customGroups),
    }
  } catch {
    return defaultNavPrefs()
  }
}

export function saveNavPrefs(prefs: NavPrefs): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(prefs))
  } catch {
    // 隐私模式可能禁止写入：忽略即可，排序退化成默认值
  }
}