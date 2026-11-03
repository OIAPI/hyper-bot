import { computed, shallowRef, watch, type App, type Component } from 'vue'
import type { UiAdapter, UiPart } from './types'
import { UI_ADAPTERS } from './registry'
import { hslToHex, uiTheme } from './theme-bridge'
import { useThemeStore } from '@/stores/theme'

const adapter = shallowRef<UiAdapter | null>(null)
const activeId = shallowRef('element')
const loading = shallowRef(true)
const failed = shallowRef<string | null>(null)
const cache = new Map<string, UiAdapter>()

let host: App | null = null

export function useUiAdapter() {
  return adapter
}

/** 每个 Ui* 壳组件用它解析当前框架的实现；切换框架会自动重渲染 */
export function useUiPart(name: UiPart) {
  return computed<Component | null>(() => adapter.value?.components?.[name] ?? null)
}

export function useUiRuntime() {
  return { adapter, activeId, loading, failed }
}

/** 切换框架：懒加载适配器 -> 安装 -> 换引用（不刷新页面） */
export async function loadFramework(id: string): Promise<void> {
  const known = Boolean(UI_ADAPTERS[id])
  const key = known ? id : 'element'
  loading.value = true
  try {
    let next = cache.get(key)
    if (!next) {
      const mod = await UI_ADAPTERS[key]()
      next = mod.default
      cache.set(key, next)
      if (host && next.install) next.install(host)
    }
    adapter.value = next
    activeId.value = next.id
    next.applyTheme?.({ accent: uiTheme.accent, dark: uiTheme.dark })
    failed.value = null
  } catch (err) {
    failed.value = err instanceof Error ? err.message : String(err)
    console.error('[ui] 框架加载失败', id, err)
  } finally {
    loading.value = false
  }
}

/** main.ts 挂载前调用：同步主题令牌 + 首次加载 */
export async function initUi(app: App): Promise<void> {
  host = app
  const theme = useThemeStore()

  const syncTokens = (): void => {
    uiTheme.accent = hslToHex(theme.hue, theme.sat, theme.light)
    uiTheme.dark = theme.effective === 'dark'
    uiTheme.hue = theme.hue
    uiTheme.sat = theme.sat
    uiTheme.light = theme.light
    adapter.value?.applyTheme?.({ accent: uiTheme.accent, dark: uiTheme.dark })
  }

  syncTokens()
  watch(() => [theme.hue, theme.sat, theme.light, theme.effective] as const, syncTokens)
  watch(() => theme.framework, (id) => void loadFramework(id))

  await loadFramework(theme.framework)
}