import type { UiAdapter } from './types'

export const UI_ADAPTERS: Record<string, () => Promise<{ default: UiAdapter }>> = {
  /** 轻量适配器：几 KB，先渲染出来再在后台换成组件库 */
  native: () => import('./adapters/native'),
  element: () => import('./adapters/element'),
  antd: () => import('./adapters/antd'),
  naive: () => import('./adapters/naive'),
  arco: () => import('./adapters/arco'),
}