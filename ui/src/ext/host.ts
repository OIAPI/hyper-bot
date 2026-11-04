/**
 * 插件前端宿主（v3.2）
 *
 * 后端 /webui/api/ext/scripts 返回插件自带的 ES module 地址，这里把它们
 * 动态 import 进来，交给插件的 setup(ctx) 自己决定做什么：
 *  - ctx.registerWidget(slot, { title, component })  在已有页面里长出一块
 *  - ctx.registerRoute({ path, title, component })    往侧边栏加一个页面
 *  - ctx.h / ctx.vue.* / ctx.ui.*                    直接用 Vue 与当前组件库
 *
 * 设计约束：
 *  1. 插件模块是原生 ES module，不能 import 'vue'（浏览器没有裸模块解析），
 *     所以 Vue 的 API 只能从 ctx 里拿——这是有意的，也让插件不受构建影响。
 *  2. 插件炸掉不能带走面板：import 失败、setup 抛错都只 console.warn。
 *  3. 只加载一次（登录后由外壳触发），刷新页面才重新拉。
 */
import {
  computed, defineComponent, h, nextTick, onMounted, onUnmounted,
  reactive, ref, shallowReactive, watch, markRaw, type Component,
} from 'vue'
import { router } from '@/router'
import { extApi } from '@/api'
import * as ui from '@/ui/components'
import { useToastStore } from '@/stores/toast'

export interface PluginWidgetEntry {
  key: string
  plugin: string
  pluginName: string
  slot: string
  title: string
  component: Component
}

export interface PluginRouteEntry {
  name: string
  plugin: string
  path: string        // 已带前导 /，用于侧边栏
  title: string
  icon: string
  group: string
}

export interface PluginWidgetInput {
  title?: string
  component: Component
}

export interface PluginRouteInput {
  path: string
  title: string
  component: Component
  icon?: string
  group?: string
}

export interface PluginContext {
  plugin: string
  name: string
  h: typeof h
  ref: typeof ref
  computed: typeof computed
  reactive: typeof reactive
  watch: typeof watch
  nextTick: typeof nextTick
  onMounted: typeof onMounted
  onUnmounted: typeof onUnmounted
  defineComponent: typeof defineComponent
  vue: Record<string, unknown>
  ui: Record<string, Component>
  extApi: typeof extApi
  router: typeof router
  toast: ReturnType<typeof useToastStore>
  registerWidget: (slot: string, widget: PluginWidgetInput) => void
  registerRoute: (route: PluginRouteInput) => void
  log: (...args: unknown[]) => void
}

/** 按 slot 聚合的注入块（reactive：注册完立刻渲染，不用刷新） */
const widgetMap = reactive<Record<string, PluginWidgetEntry[]>>({})
/** 插件注册的页面（AppLayout 用它合并侧边栏，不依赖 router.getRoutes 的响应性） */
const routeList = shallowReactive<PluginRouteEntry[]>([])
const loaded = new Set<string>()
const failures = reactive<Record<string, string>>({})
let booting: Promise<void> | null = null

export const pluginWidgets = widgetMap
export const pluginRoutes = routeList
export const pluginFailures = failures

export function widgetsFor(slot: string): PluginWidgetEntry[] {
  return widgetMap[slot] ?? []
}

function normalPath(raw: string): string {
  let p = (raw || '').trim()
  if (!p.startsWith('/')) p = '/' + p
  return p.replace(/\/{2,}/g, '/')
}

function childPath(raw: string): string {
  return normalPath(raw).replace(/^\//, '')
}

function routeName(plugin: string, path: string): string {
  const short = plugin.split('\\').pop() || plugin
  return 'ext-' + short.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + childPath(path).replace(/[^a-zA-Z0-9]+/g, '-')
}

function contextFor(plugin: string, name: string): PluginContext {
  const short = plugin.split('\\').pop() || plugin
  const label = name || short
  return {
    plugin,
    name: label,
    h, ref, computed, reactive, watch, nextTick,
    onMounted, onUnmounted, defineComponent,
    vue: {
      h, ref, computed, reactive, watch, nextTick, onMounted, onUnmounted, defineComponent, markRaw,
    },
    ui: ui as unknown as Record<string, Component>,
    extApi,
    router,
    toast: useToastStore(),
    registerWidget(slot: string, widget: PluginWidgetInput): void {
      if (!widget || typeof widget.component !== 'object') {
        console.warn('[plugin:' + label + '] registerWidget 缺少 component')
        return
      }
      const key = slot + ':' + label + ':' + (widgetMap[slot]?.length ?? 0) + ':' + widget.title
      const list = widgetMap[slot] ?? []
      list.push({
        key,
        plugin,
        pluginName: label,
        slot,
        title: widget.title || label,
        component: markRaw(widget.component),
      })
      widgetMap[slot] = list
    },
    registerRoute(route: PluginRouteInput): void {
      if (!route || !route.component || !route.path || !route.title) {
        console.warn('[plugin:' + label + '] registerRoute 参数不完整')
        return
      }
      const full = normalPath(route.path)
      const name = routeName(plugin, full)
      const meta = {
        title: route.title,
        icon: route.icon || 'plugins',
        group: route.group || '插件',
        plugin: label,
      }
      try {
        if (router.hasRoute(name)) router.removeRoute(name)
        router.addRoute('shell', {
          path: childPath(full),
          name,
          component: markRaw(route.component),
          meta,
        })
      } catch (e) {
        console.warn('[plugin:' + label + '] 注册路由失败', e)
        return
      }
      const existing = routeList.findIndex((r) => r.name === name)
      const entry: PluginRouteEntry = {
        name,
        plugin: label,
        path: full,
        title: route.title,
        icon: meta.icon,
        group: meta.group,
      }
      if (existing >= 0) routeList.splice(existing, 1, entry)
      else routeList.push(entry)
    },
    log: (...args: unknown[]) => console.log('[plugin:' + label + ']', ...args),
  }
}

function moduleSetup(mod: unknown): ((ctx: PluginContext) => void) | null {
  if (!mod || typeof mod !== 'object') return null
  const record = mod as Record<string, unknown>
  const candidates = [record.default, mod]
  for (const candidate of candidates) {
    if (!candidate) continue
    if (typeof candidate === 'function') return candidate as (ctx: PluginContext) => void
    const setup = (candidate as Record<string, unknown>).setup
    if (typeof setup === 'function') return setup as (ctx: PluginContext) => void
  }
  return null
}

function linkCss(url: string, plugin: string): void {
  if (!url || document.querySelector('link[data-plugin-css="' + url + '"]')) return
  const link = document.createElement('link')
  link.rel = 'stylesheet'
  link.href = url
  link.dataset.pluginCss = url
  link.dataset.plugin = plugin
  document.head.appendChild(link)
}

/**
 * 拉取并执行所有插件脚本。重复调用只会执行一次；失败只记录，不抛。
 * force=true 时清掉记录重新加载（开发调试用）。
 */
export function ensurePluginHost(force = false): Promise<void> {
  if (force) {
    booting = null
    loaded.clear()
    for (const key of Object.keys(failures)) delete failures[key]
    // 插件重载后旧的注入块/页面必须先清掉，否则会重复注册、菜单也不刷新
    for (const slot of Object.keys(widgetMap)) delete widgetMap[slot]
    for (const entry of [...routeList]) {
      if (router.hasRoute(entry.name)) router.removeRoute(entry.name)
    }
    routeList.splice(0, routeList.length)
  }
  if (booting) return booting
  booting = (async () => {
    let items: Array<{ plugin: string; url: string; type: string; name?: string; title?: string }> = []
    try {
      const res = await extApi.scripts()
      items = res.items ?? []
    } catch (e) {
      // 后端还没升级 / 没登录：静默跳过，不影响面板本身
      console.warn('[plugin-host] 读取插件脚本失败', e)
      return
    }
    for (const item of items) {
      const plugin = item.plugin || ''
      if (!plugin || !item.url) continue
      if (item.type === 'css') {
        linkCss(item.url, plugin)
        continue
      }
      if (item.type !== 'js' || loaded.has(plugin)) continue
      loaded.add(plugin)
      try {
        // force 时加时间戳，绕开浏览器对同一个 module URL 的缓存
        const url = force ? item.url + (item.url.includes('?') ? '&' : '?') + 'v=' + Date.now() : item.url
        const mod = await import(/* @vite-ignore */ url)
        const setup = moduleSetup(mod)
        if (!setup) {
          failures[plugin] = '模块没有导出 setup(ctx)'
          console.warn('[plugin-host] ' + plugin + ' 没有导出 setup(ctx)，已跳过')
          continue
        }
        setup.call(mod, contextFor(plugin, item.title || item.name || plugin))
      } catch (e) {
        const msg = e instanceof Error ? e.message : String(e)
        failures[plugin] = msg
        console.warn('[plugin-host] ' + plugin + ' 加载失败：' + msg, e)
      }
    }
  })()
  return booting
}

/** 重新加载（开发时改完插件 JS，执行 window.hyperReloadPlugins()） */
export function reloadPluginHost(): Promise<void> {
  for (const key of Object.keys(widgetMap)) delete widgetMap[key]
  routeList.splice(0, routeList.length)
  return ensurePluginHost(true)
}

declare global {
  interface Window {
    hyperReloadPlugins?: () => Promise<void>
  }
}
if (typeof window !== 'undefined') {
  window.hyperReloadPlugins = reloadPluginHost
}
