import { computed, shallowRef, watch, type App, type Component } from 'vue'
import type { UiAdapter, UiPart } from './types'
import { UI_ADAPTERS } from './registry'
import elementAdapter from './adapters/element'
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
      // element 是默认框架，直接静态打包进主 bundle：
      // 少一次网络往返，页面第一次渲染就有真组件，不会出现「只有文字、控件全空」。
      // 其它框架仍然按需懒加载，切过去才下载。
      const mod = key === 'element' ? { default: elementAdapter } : await UI_ADAPTERS[key]()
      next = mod.default
      cache.set(key, next)
      if (host && next.install) next.install(host)
    }
    adapter.value = next
    activeId.value = next.id
    // 调试用：任何页面 ?diag=1 时会把这两项打印出来
    ;(window as unknown as Record<string, unknown>).__HYPER_UI__ = {
      id: next.id,
      parts: Object.keys(next.components ?? {}).length,
      root: Boolean(next.Root),
    }
    next.applyTheme?.({ accent: uiTheme.accent, dark: uiTheme.dark })
    failed.value = null
  } catch (err) {
    failed.value = err instanceof Error ? err.message : String(err)
    console.error('[ui] 框架加载失败', id, err)
  } finally {
    loading.value = false
  }
}

/** 兜底：8 秒还没就绪就把状态标成失败，界面会显示红条而不是一片空白 */
function watchAdapter(): void {
  window.setTimeout(() => {
    if (adapter.value === null && failed.value === null) {
      failed.value = '组件库加载超时（8 秒）。页面可以继续操作，但控件样式缺失，请检查 /webui/assets 是否可访问'
    }
  }, 8000)
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

  watchAdapter()
  syncTokens()
  watch(() => [theme.hue, theme.sat, theme.light, theme.effective] as const, syncTokens)
  watch(() => theme.framework, (id) => void loadFramework(id))

  await loadFramework(theme.framework)
}