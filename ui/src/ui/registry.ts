import type { UiAdapter } from './types'

export const UI_ADAPTERS: Record<string, () => Promise<{ default: UiAdapter }>> = {
  element: () => import('./adapters/element'),
  antd: () => import('./adapters/antd'),
  naive: () => import('./adapters/naive'),
  arco: () => import('./adapters/arco'),
}