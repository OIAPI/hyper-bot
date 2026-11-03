import type { UiAdapter } from './types'
import elementAdapter from './adapters/element'

export const UI_ADAPTERS: Record<string, () => Promise<{ default: UiAdapter }>> = {
  // element 已经静态打进主包（首屏就要用），这里直接复用，避免动态+静态双份引入
  element: () => Promise.resolve({ default: elementAdapter }),
  antd: () => import('./adapters/antd'),
  naive: () => import('./adapters/naive'),
  arco: () => import('./adapters/arco'),
}