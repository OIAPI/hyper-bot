/**
 * 页面区块布局偏好：按路由存 localStorage。
 * 目前用「弹窗排序 + 隐藏」，后续可以换成页面上直接拖。
 */

export interface PageLayoutPrefs {
  order: string[]
  hidden: string[]
}

const PREFIX = 'hyper-page-layout:'

export function defaultPageLayout(): PageLayoutPrefs {
  return { order: [], hidden: [] }
}

export function loadPageLayout(path: string): PageLayoutPrefs {
  try {
    const raw = localStorage.getItem(PREFIX + path)
    if (!raw) return defaultPageLayout()
    const parsed = JSON.parse(raw) as Partial<PageLayoutPrefs>
    return {
      order: Array.isArray(parsed.order) ? parsed.order.filter((x): x is string => typeof x === 'string') : [],
      hidden: Array.isArray(parsed.hidden) ? parsed.hidden.filter((x): x is string => typeof x === 'string') : [],
    }
  } catch {
    return defaultPageLayout()
  }
}

export function savePageLayout(path: string, prefs: PageLayoutPrefs): void {
  try {
    localStorage.setItem(PREFIX + path, JSON.stringify(prefs))
  } catch {
    // 隐私模式写不进去就退化成默认布局
  }
}