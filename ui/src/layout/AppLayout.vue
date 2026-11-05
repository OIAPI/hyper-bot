<script setup lang="ts">
/**
 * 应用外壳 · V6「框架无关」
 * 三件事各自独立，且都不刷新页面：
 *  - 组件库（data-framework + 懒加载适配器）：控件几何、层次、字重整体切换
 *  - 明暗（data-theme / html.dark）
 *  - 主题色（--brand-h/s/l 派生 --accent，适配器 applyTheme 再同步给各组件库）
 * 导航与外壳用自有标记 + CSS，不做成组件库控件——它是品牌骨架，切换框架时不应跳动。
 */
import { ref, computed, nextTick, onMounted, onUnmounted, watch, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Odometer, Connection, Download, Document, ChatDotRound, Grid, Box,
  Tickets, Setting, Cpu, Tools, User, Fold, Expand, Menu as MenuIcon, ArrowDown,
  Postcard, Timer, Lock, Refresh, Link, Bell,
} from '@element-plus/icons-vue'
import {
  useThemeStore, THEME_PRESETS, FRAMEWORKS, type ThemeMode, type FrameworkId,
} from '@/stores/theme'
import { useUiRuntime, loadFramework } from '@/ui/provider'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { overviewApi, authApi, extApi, accountApi } from '@/api'
import { scopedAccount, setScopedAccount } from '@/stores/accountScope'
import { formatUptime } from '@/utils/format'
import { ensurePluginHost, pluginRoutes } from '@/ext/host'
import AppToast from '@/components/AppToast.vue'
import { defaultNavPrefs, loadNavPrefs, saveNavPrefs, type NavPrefs } from './navPrefs'

const route = useRoute()
const router = useRouter()

// 页面级通用布局：按路由记住顶层区块的顺序/隐藏；不需要每个 View 改代码
const contentRef = ref<HTMLElement | null>(null)
const pageEdit = ref(false)
const pageBlocks = ref<Array<{ index: number; label: string }>>([])
const pageOrder = ref<number[]>([])
const pageHidden = ref<number[]>([])
let pageDragFrom = -1
let pageDragging = false

// v2：旧版本会把组件库浮层也算成区块，索引错位；换前缀让旧偏好一次性失效，避免藏错区块
const PAGE_PREF_PREFIX = 'hyper-page-blocks:v2:'

function loadPagePrefs(path: string): void {
  try {
    const raw = localStorage.getItem(PAGE_PREF_PREFIX + path)
    if (!raw) {
      pageOrder.value = []
      pageHidden.value = []
      return
    }
    const parsed = JSON.parse(raw) as { order?: number[]; hidden?: number[] }
    pageOrder.value = Array.isArray(parsed.order) ? parsed.order.filter((n) => typeof n === 'number') : []
    pageHidden.value = Array.isArray(parsed.hidden) ? parsed.hidden.filter((n) => typeof n === 'number') : []
  } catch {
    pageOrder.value = []
    pageHidden.value = []
  }
}

function savePagePrefs(): void {
  try {
    localStorage.setItem(
      PAGE_PREF_PREFIX + route.path,
      JSON.stringify({ order: pageOrder.value, hidden: pageHidden.value }),
    )
  } catch {
    // 隐私模式写不进去就退化成默认布局
  }
}

function viewRoot(): HTMLElement | null {
  const host = contentRef.value
  if (!host) return null
  for (const child of Array.from(host.children)) {
    if (
      child instanceof HTMLElement
      && !child.classList.contains('route-loading')
      && !child.classList.contains('ui-progress')
      && !child.classList.contains('layout-inline-bar')
    ) {
      return child
    }
  }
  return null
}

/** 当前页面的标题（路由 meta.title 优先，其次页头 h1） */
function pageTitle(): string {
  const meta = (route.meta.title as string | undefined) ?? ''
  if (meta !== '') return meta
  const h1 = document.querySelector('.page-head h1')?.textContent ?? ''
  return h1.replace(/\s+/g, ' ').trim()
}

/**
 * 区块名称：优先用真实标题（卡片头 / 页面标题），没有标题的卡按内容类型命名
 * （日志筛选 / 日志列表 / 运行概览），不再出现「区块 1」这种占位名。
 */
function blockLabel(el: HTMLElement, index: number): string {
  void index
  // 1) 视图可以显式声明 data-layout-label
  const explicit = el.dataset.layoutLabel ?? el.getAttribute('aria-label') ?? el.getAttribute('title') ?? ''
  if (explicit.trim() !== '') return explicit.trim().slice(0, 16)

  // 2) 卡片头 / 标题（Element / Ant / Naive / Arco 都认）
  const node = el.querySelector(
    '.card-title, .el-card__header .card-title, .ant-card-head-title, .n-card-header__main, '
    + '.arco-card-header-title, h2, h3, h4, .page-title, .title, strong',
  )
  const text = (node?.textContent ?? '').replace(/\s+/g, ' ').trim()
  if (text !== '') return text.slice(0, 16)

  // 3) 没有卡片头：用前面紧挨着的小节标题（事件通道 / 按群覆盖…）当名字
  const prev = sectionTitleOf(el)
  if (prev) {
    const section = (prev.textContent ?? '').replace(/\s+/g, ' ').trim()
    if (section !== '') return section.slice(0, 16)
  }

  // 4) 无标题卡：按内容判断用途，名字是「页面标题 + 用途」
  const title = pageTitle()
  if (el.querySelector('table, .el-table, .ant-table, .n-data-table, .arco-table')) {
    return (title || '数据') + '列表'
  }
  if (el.querySelector('input, textarea, select, .el-select, .ant-select, .n-select, .arco-select')) {
    return (title || '页面') + '筛选'
  }
  if (el.querySelector('.el-descriptions, .ant-descriptions, .arco-descriptions, .stat, .kpi-card, .el-progress')) {
    return (title || '页面') + '概览'
  }
  return title !== '' ? title + '内容' : '页面内容'
}

/**
 * 不能当作「页面区块」参与排序/隐藏的节点。
 *
 * 组件库的浮层（Element Plus 的 dialog / popper）在 teleport 关闭时仍然渲染在
 * 页面里，只是靠 v-show 写成行内 display:none 来隐藏。页面布局如果把它当成区块，
 * 恢复布局时会写 el.style.display = ''，等于把 v-show 的 display:none 抹掉：
 * 弹窗没打开，一个全屏半透明遮罩却盖在页面上，点什么都点不动。
 */
const NON_BLOCK_SELECTOR = [
  '.el-overlay', '.el-overlay-dialog', '.el-popper', '.el-loading-mask',
  '.v-modal', '.mobile-mask', '.route-loading', '.ui-progress',
  // 装饰性元素：小节标题 / 分割线 / 提示条不是「可拖动的区块」
  '.section-title', '[class*="section-title"]', '.divider', '.divider-line',
  '.card-divider', '.page-divider', '.form-divider',
  '[role="dialog"]', '[role="tooltip"]', '[data-layout-skip]',
].join(',')

function isPageBlock(el: HTMLElement): boolean {
  if (!el.matches('div,section,article,main,form,aside,ul,ol,table')) return false
  if (el.matches('header,.page-head,.layout-bar,' + NON_BLOCK_SELECTOR)) return false
  if (el.closest(NON_BLOCK_SELECTOR)) return false
  // 浮层基本都是 fixed/absolute：不参与文档流的节点不是「区块」
  const position = getComputedStyle(el).position
  return position !== 'fixed' && position !== 'absolute'
}

function collectPageBlocks(): void {
  const root = viewRoot()
  if (!root) {
    pageBlocks.value = []
    return
  }
  // 先清掉上一轮的标记，避免浮层残留 data-auto-block 被后续 applyPageLayout 命中
  for (const child of Array.from(root.children)) {
    if (child instanceof HTMLElement && child.dataset.autoBlock !== undefined) {
      delete child.dataset.autoBlock
      delete child.dataset.layoutPrevDisplay
    }
  }
  const children = Array.from(root.children).filter((el): el is HTMLElement =>
    el instanceof HTMLElement && isPageBlock(el),
  )
  pageBlocks.value = children.map((el, index) => {
    el.dataset.autoBlock = String(index)
    return { index, label: blockLabel(el, index) }
  })
  const valid = new Set(pageBlocks.value.map((block) => block.index))
  pageOrder.value = pageOrder.value.filter((index) => valid.has(index))
  pageHidden.value = pageHidden.value.filter((index) => valid.has(index))
  applyPageLayout()
}

function currentPageOrder(): number[] {
  const order = pageOrder.value.length ? [...pageOrder.value] : pageBlocks.value.map((block) => block.index)
  for (const block of pageBlocks.value) {
    if (!order.includes(block.index)) order.push(block.index)
  }
  return order
}

/**
 * 紧贴在区块前面的小节标题（装饰元素，不参与拖拽，但要跟着区块走）。
 * 编辑态下区块前面会插一条操作条，所以要先跳过 bar 再判断。
 */
const SECTION_TITLE_SELECTOR = '.section-title, [class*="section-title"], .section-head'
function sectionTitleOf(el: HTMLElement): HTMLElement | null {
  let prev = el.previousElementSibling
  while (prev instanceof HTMLElement && prev.classList.contains(EDIT_BAR_CLASS)) {
    prev = prev.previousElementSibling
  }
  if (prev instanceof HTMLElement && prev.matches(SECTION_TITLE_SELECTOR)) return prev
  return null
}

function applyPageLayout(): void {
  const root = viewRoot()
  if (!root) return
  const order = currentPageOrder()
  const hidden = new Set(pageHidden.value)
  for (const block of pageBlocks.value) {
    const el = root.querySelector<HTMLElement>('[data-auto-block="' + block.index + '"]')
    if (!el) continue
    const pos = order.indexOf(block.index)
    el.style.order = String(pos === -1 ? order.length : pos)
    // 操作条是区块的兄弟节点：order 必须同步，否则拖走的是内容、操作条留在原地
    // 操作条现在插在「小节标题」前面，不再是卡片的 previousElementSibling，
    // 必须按 data-for 找，否则它的 order 一直是默认 0，会全部堆到页面顶部。
    const bar = root.querySelector<HTMLElement>('.' + EDIT_BAR_CLASS + '[data-for="' + block.index + '"]')
    if (bar) {
      bar.style.order = el.style.order
      bar.style.opacity = hidden.has(block.index) ? '0.45' : ''
    }
    // 小节标题跟着后面的卡片一起排序/隐藏，避免「卡片藏了、标题还留在原地」
    const title = sectionTitleOf(el)
    if (title) {
      title.style.order = el.style.order
      if (hidden.has(block.index) && !pageEdit.value) title.style.display = 'none'
      else title.style.display = ''
    }
    if (hidden.has(block.index)) {
      if (pageEdit.value) {
        // 编辑态不真正隐藏：只淡化并保留「显示」按钮，否则藏起来的块再也点不回来
        if (el.dataset.layoutPrevDisplay !== undefined) {
          el.style.display = el.dataset.layoutPrevDisplay
          delete el.dataset.layoutPrevDisplay
        }
        el.classList.add('layout-block-off')
      } else {
        // 记住原来的行内 display，恢复时原样写回：绝不无条件清空，
        // 否则会把组件自己（v-show / 浮层）控制的 display 一起抹掉。
        el.classList.remove('layout-block-off')
        if (el.style.display !== 'none') {
          el.dataset.layoutPrevDisplay = el.style.display
          el.style.display = 'none'
        }
      }
    } else {
      el.classList.remove('layout-block-off')
      if (el.dataset.layoutPrevDisplay !== undefined) {
        el.style.display = el.dataset.layoutPrevDisplay
        delete el.dataset.layoutPrevDisplay
      }
    }
  }
}

function refreshPageLayout(): void {
  if (route.path === '/') return
  loadPagePrefs(route.path)
  window.setTimeout(() => {
    collectPageBlocks()
    applyPageLayout()
    cleanOrphanOverlays()
  }, 300)
}

const EDIT_BAR_CLASS = 'block-edit-bar'
const TOGGLE_BAR_CLASS = 'layout-inline-bar'
let layoutSyncTimer = 0

function makeBarButton(text: string, title: string, onClick: () => void): HTMLButtonElement {
  const btn = document.createElement('button')
  btn.type = 'button'
  btn.className = 'be-btn'
  btn.textContent = text
  if (title !== '') btn.title = title
  btn.addEventListener('click', (event) => {
    event.stopPropagation()
    onClick()
  })
  return btn
}

/**
 * 概览页同款：页面顶部一个「编辑布局 / 完成」按钮。
 * 注入到页面根节点里（不是顶栏、不是弹窗），这样和概览页的 .layout-bar 位置一致。
 */
function ensureToggleBar(): void {
  const host = contentRef.value
  const root = viewRoot()
  if (!host) return
  if (!root || route.path === '/' || !pageBlocks.value.length) {
    host.querySelectorAll<HTMLElement>('.' + TOGGLE_BAR_CLASS).forEach((bar) => bar.remove())
    return
  }
  let bar = root.querySelector<HTMLElement>(':scope > .' + TOGGLE_BAR_CLASS)
  if (!bar) {
    bar = document.createElement('div')
    bar.className = TOGGLE_BAR_CLASS
    bar.dataset.layoutSkip = '1'
    bar.style.order = '-1'
    const btn = document.createElement('button')
    btn.type = 'button'
    btn.className = 'layout-inline-btn'
    btn.addEventListener('click', (event) => {
      event.stopPropagation()
      togglePageEdit()
    })
    bar.appendChild(btn)
    // 有页头就放在页头下面（标题之上太突兀），没有就从最前面开始
    const head = root.querySelector<HTMLElement>(':scope > .page-head')
    if (head) root.insertBefore(bar, head.nextSibling)
    else root.insertBefore(bar, root.firstChild)
  }
  const label = pageEdit.value ? '完成' : '编辑布局'
  const btn = bar.querySelector('button')
  if (btn && btn.textContent !== label) btn.textContent = label
}

function removeToggleBar(): void {
  contentRef.value?.querySelectorAll<HTMLElement>('.' + TOGGLE_BAR_CLASS).forEach((bar) => bar.remove())
}

/** 编辑态：在每个区块上方插一条与概览页 LayoutBlock 一致的操作条 */
function refreshEditBars(): void {
  const host = contentRef.value
  const root = viewRoot()
  if (!pageEdit.value || !host || !root) return
  const alive = new Set<number>()
  for (const block of pageBlocks.value) {
    const el = root.querySelector<HTMLElement>('[data-auto-block="' + block.index + '"]')
    if (!el || !el.parentElement) continue
    alive.add(block.index)
    // 先按 data-for 找已存在的操作条，而不是只看 previousElementSibling：
    // 条插在「小节标题」前面时，卡片的 previousElementSibling 是标题，
    // 只看前一个兄弟会误判为「没有条」，于是每 700ms 再插一条——直接无限增殖。
    const owned = host.querySelectorAll<HTMLElement>('.' + EDIT_BAR_CLASS + '[data-for="' + block.index + '"]')
    owned.forEach((item, i) => { if (i > 0) item.remove() })
    let bar = owned[0] ?? null
    if (!bar) {
      bar = document.createElement('div')
      bar.className = EDIT_BAR_CLASS
      bar.dataset.layoutSkip = '1'
      bar.dataset.for = String(block.index)
    }
    const anchor = sectionTitleOf(el) ?? el
    if (bar.nextElementSibling !== anchor || bar.parentElement !== el.parentElement) {
      el.parentElement.insertBefore(bar, anchor)
    }
    bar.textContent = ''
    if (narrow.value) {
      bar.appendChild(makeBarButton('↑', '上移', () => shiftPageBlock(block.index, -1)))
      bar.appendChild(makeBarButton('↓', '下移', () => shiftPageBlock(block.index, 1)))
    } else {
      const grip = document.createElement('span')
      grip.className = 'be-grip'
      grip.textContent = '⋮⋮'
      grip.title = '拖动排序'
      grip.addEventListener('pointerdown', (event) => startBarDrag(block.index, event))
      bar.appendChild(grip)
    }
    const name = document.createElement('span')
    name.className = 'be-name'
    name.textContent = block.label
    bar.appendChild(name)
    const spacer = document.createElement('span')
    spacer.className = 'be-spacer'
    bar.appendChild(spacer)
    bar.appendChild(makeBarButton(
      pageHidden.value.includes(block.index) ? '显示' : '隐藏',
      '',
      () => togglePageHidden(block.index),
    ))
  }
  host.querySelectorAll<HTMLElement>('.' + EDIT_BAR_CLASS).forEach((bar) => {
    if (!alive.has(Number(bar.dataset.for ?? -1))) bar.remove()
  })
  // 操作条建好后同步 order / 淡化状态
  applyPageLayout()
}

function removeEditBars(): void {
  contentRef.value?.querySelectorAll<HTMLElement>('.' + EDIT_BAR_CLASS).forEach((bar) => bar.remove())
  contentRef.value?.querySelectorAll<HTMLElement>('.layout-block-off').forEach((el) => el.classList.remove('layout-block-off'))
}

/** 从区块上方的拖动柄开始拖（条是区块的兄弟节点，不能复用区块内的 pointerdown 判定） */
function startBarDrag(index: number, event: PointerEvent): void {
  event.preventDefault()
  event.stopPropagation()
  pageDragFrom = index
  pageDragging = true
  window.addEventListener('pointermove', onPagePointerMove)
  window.addEventListener('pointerup', stopPageDrag, { once: true })
  window.addEventListener('pointercancel', stopPageDrag, { once: true })
}

function syncPageLayoutUi(): void {
  ensureToggleBar()
  if (pageEdit.value) refreshEditBars()
}

function startLayoutSync(): void {
  window.clearInterval(layoutSyncTimer)
  layoutSyncTimer = window.setInterval(syncPageLayoutUi, 700)
  syncPageLayoutUi()
}

function togglePageEdit(): void {
  if (route.path === '/') return
  pageEdit.value = !pageEdit.value
  collectPageBlocks()
  applyPageLayout()
  if (pageEdit.value) refreshEditBars()
  else removeEditBars()
  ensureToggleBar()
}

function shiftPageBlock(index: number, delta: number): void {
  const order = currentPageOrder()
  const from = order.indexOf(index)
  const to = from + delta
  if (from === -1 || to < 0 || to >= order.length) return
  order.splice(from, 1)
  order.splice(to, 0, index)
  pageOrder.value = order
  applyPageLayout()
  savePagePrefs()
}

function togglePageHidden(index: number): void {
  const hidden = new Set(pageHidden.value)
  if (hidden.has(index)) hidden.delete(index)
  else hidden.add(index)
  pageHidden.value = [...hidden]
  applyPageLayout()
  savePagePrefs()
}

function onPagePointerDown(event: PointerEvent): void {
  if (!pageEdit.value) return
  const target = event.target as HTMLElement | null
  if (!target) return
  if (target.closest('button,a,input,select,textarea,label')) return
  const block = target.closest<HTMLElement>('[data-auto-block]')
  if (!block) return
  event.preventDefault()
  pageDragFrom = Number(block.dataset.autoBlock)
  pageDragging = true
  window.addEventListener('pointermove', onPagePointerMove)
  window.addEventListener('pointerup', stopPageDrag, { once: true })
  window.addEventListener('pointercancel', stopPageDrag, { once: true })
}

function onPagePointerMove(event: PointerEvent): void {
  if (!pageDragging) return
  const target = (document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null)?.closest<HTMLElement>('[data-auto-block]')
  if (!target) return
  const to = Number(target.dataset.autoBlock)
  if (to === pageDragFrom) return
  const order = currentPageOrder()
  const from = order.indexOf(pageDragFrom)
  const dest = order.indexOf(to)
  if (from === -1 || dest === -1) return
  order.splice(from, 1)
  order.splice(dest, 0, pageDragFrom)
  pageOrder.value = order
  applyPageLayout()
  savePagePrefs()
}

function stopPageDrag(): void {
  pageDragging = false
  window.removeEventListener('pointermove', onPagePointerMove)
}

/**
 * 兜底：Element Plus 的弹窗关闭后会把 .el-overlay 留在 DOM 里（行内 display:none）。
 * 只要没有 .el-dialog 子节点，就确保它是隐藏的——空遮罩绝不能吃掉整页点击。
 * 只改行内 display、不删除节点：节点归 Vue 管理，删掉会让组件指向游离 DOM。
 */
function cleanOrphanOverlays(): void {
  document.querySelectorAll<HTMLElement>('.el-overlay').forEach((overlay) => {
    if (overlay.querySelector('.el-dialog')) return
    if (overlay.style.display !== 'none') overlay.style.display = 'none'
  })
}

watch(
  () => route.path,
  () => {
    if (pageEdit.value) {
      pageEdit.value = false
    }
    removeEditBars()
    removeToggleBar()
    refreshPageLayout()
    window.setTimeout(cleanOrphanOverlays, 160)
  },
)
const theme = useThemeStore()
watch(
  () => theme.framework,
  () => window.setTimeout(cleanOrphanOverlays, 400),
)
const auth = useAuthStore()
const toast = useToastStore()

// 组件库是独立 chunk：下载中给个提示，失败时把原因摆出来（而不是留一片空白）
const { adapter: uiAdapter, loading: uiLoading, failed: uiFailed } = useUiRuntime()
// 适配器没就绪时控件渲染为 null，这里先用占位提示，避免「一片空白」
const uiReady = computed(() => uiAdapter.value !== null)
function retryUi(): void {
  void loadFramework(theme.framework)
}
const runtime = useUiRuntime()


const collapsed = ref(localStorage.getItem('hyper-nav-collapsed') === '1')
const narrow = ref(false)
const menuCollapsed = computed(() => collapsed.value && !narrow.value)
const mobileNav = ref(false)
const themePanel = ref(false)
const uptime = ref('—')
const degraded = ref(false)

/**
 * 路由名 → 图标。用一套纯 SVG 图标（Element Plus Icons，与框架无关，切库不变），
 * 比花体字符稳定，也不会因为系统字体缺字变方块。
 */
const NAV_ICONS: Record<string, Component> = {
  overview: Odometer,
  connections: Connection,
  events: Download,
  logs: Document,
  messages: ChatDotRound,
  groups: Grid,
  plugins: Box,
  subscription: Tickets,
  config: Setting,
  runtime: Cpu,
  system: Tools,
  accounts: Postcard,
  schedule: Timer,
  permissions: Lock,
  update: Refresh,
  account: User,
  // 插件自带菜单用的图标名（adminMenu.icon）
  git: Link,
  rss: Bell,
}

// 插件注入的后台菜单（后端 schema 驱动，前端不用为每个插件改代码）
const extMenus = ref<Array<{ plugin: string; title: string; icon: string; path: string }>>([])
async function loadExtMenus(): Promise<void> {
  try {
    const res = await extApi.pages()
    extMenus.value = res.items ?? []
  } catch {
    extMenus.value = []
  }
}

interface NavEntry { path: string; title: string; name: string; icon?: string }

/**
 * 分组顺序写死：路由是 router.getRoutes() 的注册顺序，插件还会往 Map 里追加组，
 * 不显式排序的话侧边栏每次启动都可能变样。
 */
const GROUP_ORDER = ['总览', '机器人', '自动化', '运行', '插件', '系统']
/** 组内顺序：常用页面靠前；插件动态页没列进来，按标题排在每组末尾 */
const ITEM_ORDER = [
  'overview', 'connections',
  'accounts', 'groups', 'permissions',
  'subscription', 'schedule',
  'events', 'messages', 'logs', 'runtime',
  'plugins',
  'config', 'system', 'update', 'account',
]

/** 用户可自定义的排序/显示偏好（localStorage，见 navPrefs.ts） */
const navPrefs = ref<NavPrefs>(loadNavPrefs())

/** 原始分组：路由 + 插件页，按默认顺序排好；自定义顺序在 navGroups 里再套一层 */
const baseGroups = computed<Array<{ name: string; items: NavEntry[] }>>(() => {
  const groups = new Map<string, NavEntry[]>()
  const add = (group: string, entry: NavEntry): void => {
    const list = groups.get(group) ?? []
    if (!list.some((x) => x.path === entry.path)) list.push(entry)
    groups.set(group, list)
  }
  for (const r of router.getRoutes()) {
    const meta = r.meta as { title?: string; icon?: string; group?: string }
    if (!meta.title || !meta.group || !r.name) continue
    const path = r.path === '' ? '/' : r.path.startsWith('/') ? r.path : '/' + r.path
    add(meta.group, { path, title: meta.title, name: String(r.name), icon: meta.icon })
  }
  // 插件页面：router.addRoute 不会让 getRoutes() 的 computed 重新求值，
  // 所以另存一份响应式列表（host.ts 的 pluginRoutes）合并进来。
  for (const pr of pluginRoutes) {
    add(pr.group, { path: pr.path, title: pr.title, name: pr.name, icon: pr.icon })
  }
  for (const m of extMenus.value) {
    add('插件', { path: m.path, title: m.title, name: 'ext:' + m.plugin + ':' + m.path, icon: m.icon })
  }

  const rank = (item: NavEntry): number => {
    const i = ITEM_ORDER.indexOf(item.name)
    return i === -1 ? ITEM_ORDER.length : i
  }
  const groupRank = (name: string): number => {
    const i = GROUP_ORDER.indexOf(name)
    return i === -1 ? GROUP_ORDER.length : i
  }
  return [...groups.entries()]
    .filter(([, items]) => items.length > 0)
    .map(([name, items]) => ({
      name,
      items: [...items].sort((a, b) => rank(a) - rank(b) || a.title.localeCompare(b.title, 'zh-CN')),
    }))
    .sort((a, b) => groupRank(a.name) - groupRank(b.name) || a.name.localeCompare(b.name, 'zh-CN'))
})

/** 侧边栏实际展示：按 itemGroup 归属建组、过滤隐藏项、套用组序与组内顺序 */
const navGroups = computed(() => {
  const prefs = navPrefs.value
  // 编辑态把隐藏项也列出来（半透明），否则隐藏后没法在侧栏里恢复
  const hidden = new Set(editNav.value ? [] : prefs.hidden)
  const byLength = prefs.mode === 'len'
  const custom = prefs.mode === 'custom'

  const groupIndex = (name: string): number => {
    const i = prefs.groupOrder.indexOf(name)
    return i === -1 ? Number.MAX_SAFE_INTEGER : i
  }
  const itemIndex = (group: string, path: string): number => {
    const i = (prefs.itemOrder[group] ?? []).indexOf(path)
    return i === -1 ? Number.MAX_SAFE_INTEGER : i
  }

  const map = new Map<string, NavEntry[]>()
  const ensure = (name: string): NavEntry[] => {
    let list = map.get(name)
    if (!list) { list = []; map.set(name, list) }
    return list
  }
  for (const group of baseGroups.value) {
    for (const item of group.items) {
      if (hidden.has(item.path)) continue
      ensure(prefs.itemGroup[item.path] ?? group.name).push(item)
    }
  }
  // 自定义组即使为空也要显示（否则没法往里面拖东西）
  for (const name of prefs.customGroups) ensure(name)

  const groups = [...map.entries()]
    .map(([name, items]) => {
      items.sort((a, b) => {
        if (byLength) return a.title.length - b.title.length || a.title.localeCompare(b.title, 'zh-CN')
        if (custom) {
          return (
            itemIndex(name, a.path) - itemIndex(name, b.path) ||
            a.title.localeCompare(b.title, 'zh-CN')
          )
        }
        return 0
      })
      return { name, items }
    })
    .filter((group) => group.items.length > 0 || prefs.customGroups.includes(group.name))

  if (!custom) return groups
  return [...groups].sort(
    (a, b) => groupIndex(a.name) - groupIndex(b.name) || a.name.localeCompare(b.name, 'zh-CN'),
  )
})

/** 侧栏内联编辑：编辑模式下可拖动排序/隐藏；触屏用 pointer events（HTML5 DnD 在手机不触发） */
const editNav = ref(false)
const dragging = ref<{ type: 'item' | 'group'; group?: string; path?: string; name?: string } | null>(null)

function persistNavPrefs(): void {
  saveNavPrefs(navPrefs.value)
}

/** 当前实际展示的顺序（含跨组覆盖、自定义组），拖动/改名都以它为准 */
function snapshotDisplay(): { groupOrder: string[]; itemOrder: Record<string, string[]> } {
  const groupOrder: string[] = []
  const itemOrder: Record<string, string[]> = {}
  for (const group of navGroups.value) {
    groupOrder.push(group.name)
    itemOrder[group.name] = group.items.map((item) => item.path)
  }
  return { groupOrder, itemOrder }
}

/** 第一次拖动/改分组前，把当前看到的顺序固化成 custom 偏好 */
function ensureCustomOrder(): void {
  if (navPrefs.value.mode === 'custom') return
  const { groupOrder, itemOrder } = snapshotDisplay()
  navPrefs.value = { ...navPrefs.value, mode: 'custom', groupOrder, itemOrder }
  persistNavPrefs()
}

function startGroupDrag(event: PointerEvent, name: string): void {
  startDrag(event, { type: 'group', name })
}

function startItemDrag(event: PointerEvent, group: string, path: string): void {
  startDrag(event, { type: 'item', group, path })
}

function startDrag(event: PointerEvent, payload: NonNullable<typeof dragging.value>): void {
  if (!editNav.value) return
  event.preventDefault()
  ensureCustomOrder()
  dragging.value = payload
  window.addEventListener('pointermove', onDragMove)
  window.addEventListener('pointerup', stopDrag, { once: true })
  window.addEventListener('pointercancel', stopDrag, { once: true })
}

function onDragMove(event: PointerEvent): void {
  const drag = dragging.value
  if (!drag) return
  const el = document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null
  if (!el) return

  if (drag.type === 'item') {
    const path = drag.path as string
    const from = drag.group as string
    const target = el.closest<HTMLElement>('[data-nav-path]')
    if (target) {
      const to = target.dataset.navGroup
      const before = target.dataset.navPath
      if (!to || !before || before === path) return
      moveItem(path, from, to, before)
      drag.group = to
      return
    }
    // 拖到组标题上：追加到该组末尾
    const title = el.closest<HTMLElement>('[data-nav-group-title]')
    const to = title?.dataset.navGroupTitle
    if (!to || to === from) return
    moveItem(path, from, to)
    drag.group = to
    return
  }

  const target = el.closest<HTMLElement>('[data-nav-group-title]')
  const name = target?.dataset.navGroupTitle
  if (!name || name === drag.name) return
  const order = [...navPrefs.value.groupOrder]
  const from = order.indexOf(drag.name as string)
  if (from === -1) return
  order.splice(from, 1)
  const to = order.indexOf(name)
  order.splice(to === -1 ? order.length : to, 0, drag.name as string)
  navPrefs.value = { ...navPrefs.value, groupOrder: order }
  persistNavPrefs()
}

function stopDrag(): void {
  dragging.value = null
  window.removeEventListener('pointermove', onDragMove)
}

/** 手机上没有拖拽柄，改用上下箭头（在组内挪一项） */
function shiftItem(groupName: string, path: string, delta: number): void {
  ensureCustomOrder()
  const { groupOrder, itemOrder } = snapshotDisplay()
  const list = itemOrder[groupName] ?? []
  const index = list.indexOf(path)
  const target = index + delta
  if (index === -1 || target < 0 || target >= list.length) return
  list.splice(index, 1)
  list.splice(target, 0, path)
  navPrefs.value = { ...navPrefs.value, mode: 'custom', groupOrder, itemOrder }
  persistNavPrefs()
}

/** 手机端调整分组顺序 */
function shiftGroup(name: string, delta: number): void {
  ensureCustomOrder()
  const { groupOrder, itemOrder } = snapshotDisplay()
  const index = groupOrder.indexOf(name)
  const target = index + delta
  if (index === -1 || target < 0 || target >= groupOrder.length) return
  groupOrder.splice(index, 1)
  groupOrder.splice(target, 0, name)
  navPrefs.value = { ...navPrefs.value, mode: 'custom', groupOrder, itemOrder }
  persistNavPrefs()
}

/** 把一项移到目标组（同组内也走这里，beforePath 为空则追加到末尾） */
function moveItem(path: string, fromGroup: string, toGroup: string, beforePath?: string): void {
  const { groupOrder, itemOrder } = snapshotDisplay()
  const src = itemOrder[fromGroup] ?? []
  const index = src.indexOf(path)
  if (index !== -1) src.splice(index, 1)
  const dest = itemOrder[toGroup] ?? (itemOrder[toGroup] = [])
  const at = beforePath ? dest.indexOf(beforePath) : -1
  dest.splice(at === -1 ? dest.length : at, 0, path)
  if (!groupOrder.includes(toGroup)) groupOrder.push(toGroup)
  navPrefs.value = {
    ...navPrefs.value,
    mode: 'custom',
    groupOrder,
    itemOrder,
    itemGroup: { ...navPrefs.value.itemGroup, [path]: toGroup },
  }
  persistNavPrefs()
}

function allGroupNames(): string[] {
  return [...baseGroups.value.map((group) => group.name), ...navPrefs.value.customGroups]
}

function createGroup(): void {
  const name = (window.prompt('新分组名称') ?? '').trim()
  if (name === '') return
  if (allGroupNames().includes(name)) {
    window.alert('已经有同名分组了')
    return
  }
  ensureCustomOrder()
  const { groupOrder, itemOrder } = snapshotDisplay()
  groupOrder.push(name)
  itemOrder[name] = []
  navPrefs.value = {
    ...navPrefs.value,
    mode: 'custom',
    groupOrder,
    itemOrder,
    customGroups: [...navPrefs.value.customGroups, name],
  }
  persistNavPrefs()
}

function renameGroup(name: string): void {
  const next = (window.prompt('分组名称', name) ?? '').trim()
  if (next === '' || next === name) return
  if (allGroupNames().some((item) => item === next)) {
    window.alert('已经有同名分组了')
    return
  }
  ensureCustomOrder()
  const { groupOrder, itemOrder } = snapshotDisplay()
  const index = groupOrder.indexOf(name)
  if (index !== -1) groupOrder[index] = next
  itemOrder[next] = itemOrder[name] ?? []
  delete itemOrder[name]
  const itemGroup = { ...navPrefs.value.itemGroup }
  for (const [path, group] of Object.entries(itemGroup)) {
    if (group === name) itemGroup[path] = next
  }
  navPrefs.value = {
    ...navPrefs.value,
    mode: 'custom',
    groupOrder,
    itemOrder,
    itemGroup,
    customGroups: navPrefs.value.customGroups.map((item) => (item === name ? next : item)),
  }
  persistNavPrefs()
}

function removeGroup(name: string): void {
  if (!window.confirm('删除分组「' + name + '」？里面的项会回到默认分组。')) return
  ensureCustomOrder()
  const { groupOrder, itemOrder } = snapshotDisplay()
  const index = groupOrder.indexOf(name)
  if (index !== -1) groupOrder.splice(index, 1)
  delete itemOrder[name]
  const itemGroup = { ...navPrefs.value.itemGroup }
  for (const [path, group] of Object.entries(itemGroup)) {
    if (group === name) delete itemGroup[path]
  }
  navPrefs.value = {
    ...navPrefs.value,
    mode: 'custom',
    groupOrder,
    itemOrder,
    itemGroup,
    customGroups: navPrefs.value.customGroups.filter((item) => item !== name),
  }
  persistNavPrefs()
}

function toggleHidden(path: string): void {
  const hidden = new Set(navPrefs.value.hidden)
  if (hidden.has(path)) hidden.delete(path)
  else hidden.add(path)
  navPrefs.value = { ...navPrefs.value, hidden: [...hidden] }
  persistNavPrefs()
}

function resetNavLayout(): void {
  navPrefs.value = defaultNavPrefs()
  persistNavPrefs()
}

const currentTitle = computed(() => (route.meta.title as string) ?? '')
const currentGroup = computed(() => (route.meta.group as string) ?? '')
const activePath = computed(() => (route.path === '/' ? '/' : route.path.replace(/\/$/, '')))

const accentNow = computed(
  () => 'hsl(' + Math.round(theme.hue) + ' ' + Math.round(theme.sat) + '% ' + Math.round(theme.light) + '%)',
)
function presetColor(p: { hue: number; sat: number; light: number }): string {
  return 'hsl(' + p.hue + ' ' + p.sat + '% ' + p.light + '%)'
}

const themeModes: Array<{ id: ThemeMode; label: string }> = [
  { id: 'light', label: '亮色' },
  { id: 'dark', label: '暗色' },
  { id: 'auto', label: '系统' },
]

/** 窄屏时主题面板不能溢出视口 */
const panelWidth = computed(() => Math.max(248, Math.min(320, window.innerWidth - 28)))

function toggleCollapse(): void {
  collapsed.value = !collapsed.value
  localStorage.setItem('hyper-nav-collapsed', collapsed.value ? '1' : '0')
}

let timer = 0

async function refreshStatus(): Promise<void> {
  try {
    const ov = await overviewApi.index()
    uptime.value = formatUptime(ov.runtime.uptime)
    degraded.value = false
  } catch {
    uptime.value = '—'
    degraded.value = true
  }
}

const mq = window.matchMedia('(max-width: 860px)')
function syncNarrow(): void {
  narrow.value = mq.matches
}

/** 插件面板里重载/启停/导入后，通知外壳刷新插件菜单与前端模块 */
function onPluginsChanged(): void {
  void loadExtMenus()
  void ensurePluginHost(true)
}

const accountOptions = ref<Array<{ label: string; value: string }>>([])

function switchAccount(id: string): void {
  if (id === scopedAccount.value) return
  setScopedAccount(id)
  // 各页面数据都带账号上下文，切换后整页刷新最稳，也避免旧请求回填
  window.location.reload()
}

onMounted(async () => {
  startLayoutSync()
  syncNarrow()
  mq.addEventListener('change', syncNarrow)
  await auth.loadMe()
  try {
    const res = await accountApi.list()
    accountOptions.value = (res.accounts ?? []).map((a) => ({
      label: a.name ? a.name + '（' + a.id + '）' : a.id,
      value: a.id,
    }))
    if (accountOptions.value.length > 0) {
      const exists = accountOptions.value.some((a) => a.value === scopedAccount.value)
      if (!scopedAccount.value || !exists) {
        setScopedAccount(accountOptions.value[0].value)
      }
    }
  } catch {
    // 没有账号或没权限时不显示选择器，页面仍可用单账号旧逻辑
  }
  void loadExtMenus()
  // 插件自带脚本：注册注入块与页面（失败不影响面板本身）
  void ensurePluginHost()
  window.addEventListener('hyper:plugins-changed', onPluginsChanged)
  void refreshStatus()
  refreshPageLayout()
  timer = window.setInterval(refreshStatus, 30_000)
})

onUnmounted(() => {
  window.clearInterval(layoutSyncTimer)
  if (timer) window.clearInterval(timer)
  mq.removeEventListener('change', syncNarrow)
  window.removeEventListener('hyper:plugins-changed', onPluginsChanged)
})

/**
 * 切换组件库：只改 store，加载由 provider 的 watch 统一驱动（避免双份加载）。
 * 这里只把「加载中」的观感做出来，并在失败时提示。
 */
async function pickFramework(id: FrameworkId): Promise<void> {
  if (id === theme.framework && !runtime.failed.value) return
  theme.setFramework(id)
  await nextTick()
  let guard = 0
  while (runtime.loading.value && guard < 100) {
    await new Promise((r) => window.setTimeout(r, 30))
    guard += 1
  }
  if (runtime.failed.value) toast.error('该框架加载失败：' + runtime.failed.value)
}

const accountItems = [
  { key: 'account', label: '账号设置' },
  { key: 'logout', label: '退出登录', divided: true },
]

function onCommand(cmd: string): void {
  if (cmd === 'account') {
    void router.push({ name: 'account' })
    return
  }
  if (cmd === 'logout') void doLogout()
}

async function doLogout(): Promise<void> {
  try {
    await authApi.logout()
  } catch {
    // 服务端登出失败也要清本地 token，否则用户会卡在「点退出没反应」
  }
  auth.logout()
  toast.ok('已退出登录')
  void router.replace({ name: 'login' })
}
</script>

<template>
  <div class="shell" :class="{ collapsed: menuCollapsed }">
    <aside class="rail" :class="{ open: mobileNav }">
      <div class="brand">
        <div class="brand-mark">H</div>
        <div v-if="!menuCollapsed" class="brand-text">
          <div class="brand-name">Hyper Bot</div>
          <div class="brand-sub">管理面板</div>
        </div>
      </div>

      <nav class="nav-scroll">
        <div v-if="!menuCollapsed" class="nav-edit-bar">
          <button class="nav-edit-btn" type="button" @click="editNav = !editNav">
            {{ editNav ? "完成" : "编辑侧栏" }}
          </button>
          <button v-if="editNav" class="nav-edit-btn" type="button" @click="createGroup">＋ 分组</button>
          <button v-if="editNav" class="nav-edit-btn" type="button" @click="resetNavLayout">恢复默认</button>
        </div>

        <div v-for="g in navGroups" :key="g.name" class="nav-group">
          <div
            v-if="!menuCollapsed"
            class="nav-group-title"
            :class="{ editing: editNav }"
            :data-nav-group-title="editNav ? g.name : undefined"
            @pointerdown="editNav ? startGroupDrag($event, g.name) : undefined"
          >
            <template v-if="editNav">
              <template v-if="!narrow">
                <span class="drag-dots">⋮⋮</span>
              </template>
              <template v-else>
                <button class="nav-mini" type="button" title="上移分组" @click.stop="shiftGroup(g.name, -1)">↑</button>
                <button class="nav-mini" type="button" title="下移分组" @click.stop="shiftGroup(g.name, 1)">↓</button>
              </template>
            </template>
            <span class="nav-group-name">{{ g.name }}</span>
            <template v-if="editNav && navPrefs.customGroups.includes(g.name)">
              <button class="nav-mini" type="button" @pointerdown.stop @click.stop="renameGroup(g.name)">改名</button>
              <button class="nav-mini" type="button" @pointerdown.stop @click.stop="removeGroup(g.name)">删</button>
            </template>
          </div>

          <template v-for="item in g.items" :key="item.path">
            <RouterLink
              v-if="!editNav"
              :to="item.path"
              class="nav-item"
              :class="{ active: activePath === item.path }"
              :title="menuCollapsed ? item.title : undefined"
              @click="mobileNav = false"
            >
              <span class="nav-ico"><component :is="NAV_ICONS[item.icon ?? item.name] ?? Grid" /></span>
              <span v-if="!menuCollapsed" class="nav-label">{{ item.title }}</span>
            </RouterLink>
            <div
              v-else
              class="nav-item edit-item"
              :class="{ off: navPrefs.hidden.includes(item.path) }"
              :data-nav-group="g.name"
              :data-nav-path="item.path"
            >
              <template v-if="narrow">
                <button class="nav-mini" type="button" title="上移" @click.stop="shiftItem(g.name, item.path, -1)">↑</button>
                <button class="nav-mini" type="button" title="下移" @click.stop="shiftItem(g.name, item.path, 1)">↓</button>
              </template>
              <span
                v-else
                class="drag-dots"
                title="拖动排序"
                @pointerdown="startItemDrag($event, g.name, item.path)"
              >⋮⋮</span>
              <span class="nav-ico"><component :is="NAV_ICONS[item.icon ?? item.name] ?? Grid" /></span>
              <span class="nav-label">{{ item.title }}</span>
              <button class="nav-eye" type="button" title="隐藏/显示" @click="toggleHidden(item.path)">
                {{ navPrefs.hidden.includes(item.path) ? "显示" : "隐藏" }}
              </button>
            </div>
          </template>
        </div>
      </nav>

      <button class="rail-foot" type="button" @click="toggleCollapse">
        <span class="rail-foot-ico"><component :is="collapsed ? Expand : Fold" /></span>
        <span v-if="!menuCollapsed">收起侧栏</span>
      </button>
    </aside>

    <div v-if="mobileNav" class="mobile-mask" @click="mobileNav = false" />

    <main class="main">
      <header class="topbar">
        <button class="hamburger" type="button" aria-label="打开导航" @click="mobileNav = true">
          <MenuIcon />
        </button>

        <div class="title-block">
          <div v-if="currentGroup" class="crumb">{{ currentGroup }}</div>
          <h1 class="page-title">{{ currentTitle }}</h1>
        </div>

        <div class="spacer" />

        <UiSelect
          v-if="accountOptions.length"
          :model-value="scopedAccount"
          :options="accountOptions"
          size="small"
          class="topbar-account"
          @update:model-value="switchAccount"
        />

        <UiTag :type="degraded ? 'warn' : 'ok'" round size="small" class="uptime">
          <span class="dot" :class="degraded ? 'warn' : 'ok'" />
          运行 {{ uptime }}
        </UiTag>

        <UiPopover v-model="themePanel" placement="bottom-end" :width="panelWidth" trigger="click">
          <button class="icon-btn" type="button" title="外观与主题">
            <span class="accent-chip" :style="{ background: accentNow }" />
          </button>
          <template #content>
            <div class="theme-panel">
              <div class="tp-head">
                <span class="tp-title">外观</span>
                <UiButton type="text" size="small" @click="theme.reset()">恢复默认</UiButton>
              </div>

              <div class="tp-label">组件库 <span class="tp-note">切换不刷新页面</span></div>
              <div class="fw-list">
                <button
                  v-for="fw in FRAMEWORKS"
                  :key="fw.id"
                  type="button"
                  class="fw-item"
                  :class="{ active: theme.framework === fw.id, busy: runtime.loading.value }"
                  :disabled="runtime.loading.value && theme.framework !== fw.id"
                  @click="pickFramework(fw.id)"
                >
                  <span class="fw-preview" :class="'fw-' + fw.id">
                    <span class="fw-bar" />
                    <span class="fw-bar short" />
                    <span class="fw-btn" />
                  </span>
                  <span class="fw-text">
                    <span class="fw-name">{{ fw.name }}</span>
                    <span class="fw-hint">{{ fw.vendor }} · {{ fw.hint }}</span>
                  </span>
                  <span v-if="runtime.loading.value && theme.framework === fw.id" class="fw-spin" />
                  <span v-else-if="theme.framework === fw.id" class="fw-tick">✓</span>
                </button>
              </div>

              <div class="tp-label">明暗</div>
              <div class="mode-row">
                <button
                  v-for="m in themeModes"
                  :key="m.id"
                  type="button"
                  class="mode-btn"
                  :class="{ active: theme.mode === m.id }"
                  @click="theme.setMode(m.id)"
                >
                  {{ m.label }}
                </button>
              </div>

              <div class="tp-label">主题色</div>
              <div class="swatches">
                <button
                  v-for="p in THEME_PRESETS"
                  :key="p.id"
                  type="button"
                  class="swatch"
                  :class="{ active: theme.activePreset?.id === p.id }"
                  :style="{ background: presetColor(p) }"
                  :title="p.name"
                  @click="theme.applyPreset(p.id)"
                />
              </div>

              <div class="tp-label">自定义</div>
              <div class="slider-row">
                <span class="slider-name">色相</span>
                <input
                  class="range"
                  type="range" min="0" max="360" :value="theme.hue"
                  @input="theme.setAccent(Number(($event.target as HTMLInputElement).value), theme.sat, theme.light)"
                />
                <span class="slider-val">{{ Math.round(theme.hue) }}°</span>
              </div>
              <div class="slider-row">
                <span class="slider-name">饱和</span>
                <input
                  class="range"
                  type="range" min="0" max="100" :value="theme.sat"
                  @input="theme.setAccent(theme.hue, Number(($event.target as HTMLInputElement).value), theme.light)"
                />
                <span class="slider-val">{{ Math.round(theme.sat) }}%</span>
              </div>
              <div class="slider-row">
                <span class="slider-name">明度</span>
                <input
                  class="range"
                  type="range" min="30" max="80" :value="theme.light"
                  @input="theme.setAccent(theme.hue, theme.sat, Number(($event.target as HTMLInputElement).value))"
                />
                <span class="slider-val">{{ Math.round(theme.light) }}%</span>
              </div>

              <div class="tp-foot">
                <span class="mono tiny">{{ theme.accentHex }}</span>
                <UiTag size="small">{{ theme.activePreset?.name ?? '自定义' }}</UiTag>
              </div>
            </div>
          </template>
        </UiPopover>

        <UiDropdown :items="accountItems" trigger="click" @select="onCommand">
          <button class="user-chip" type="button">
            <UiAvatar :size="26" class="avatar">{{ (auth.user?.username ?? '?').slice(0, 1).toUpperCase() }}</UiAvatar>
            <span class="user-chip-name">{{ auth.user?.username ?? '—' }}</span>
            <span class="user-chip-caret"><ArrowDown /></span>
          </button>
        </UiDropdown>
      </header>

      <div v-if="uiLoading && !uiFailed" class="ui-progress"><i /></div>

      <div v-if="uiFailed" class="ui-banner">
        <span class="ui-banner-text">界面组件库加载失败：{{ uiFailed }}</span>
        <button type="button" class="ui-banner-btn" @click="retryUi">重试</button>
      </div>

      <div ref="contentRef" class="content" @pointerdown="onPagePointerDown">
        <div v-if="!uiReady" class="route-loading">
          <span class="route-spinner" />
          <span>正在加载界面组件…</span>
        </div>
        <RouterView v-else v-slot="{ Component }">
          <Transition name="fade" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </div>
    </main>

    <AppToast />

  </div>
</template>

<style scoped>
.shell { display: flex; height: 100%; background: var(--bg); }

/* ---------- 侧栏 ---------- */
.rail {
  width: var(--sidebar-w);
  flex: none;
  display: flex;
  flex-direction: column;
  background: var(--bg-rail);
  border-right: 1px solid var(--rail-border);
  transition: width var(--dur) var(--ease);
  overflow: hidden;
}
.shell.collapsed .rail { width: var(--sidebar-w-collapsed); }

.brand {
  height: var(--topbar-h);
  flex: none;
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 0 14px;
  border-bottom: 1px solid var(--rail-border);
  overflow: hidden;
  white-space: nowrap;
}
.shell.collapsed .brand { padding: 0; justify-content: center; }
.brand-mark {
  width: 28px;
  height: 28px;
  flex: none;
  border-radius: 8px;
  background: var(--accent);
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 14px;
  font-weight: 700;
}
.brand-text { min-width: 0; }
.brand-name { font-size: 13.5px; font-weight: 650; letter-spacing: -0.01em; }
.brand-sub { font-size: 10.5px; color: var(--text-faint); line-height: 1.2; }

.nav-scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 10px 8px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.shell.collapsed .nav-scroll { padding: 10px 6px; align-items: center; }
.nav-group { display: flex; flex-direction: column; gap: 2px; width: 100%; }
.nav-group + .nav-group { margin-top: 12px; }
.shell.collapsed .nav-group + .nav-group { margin-top: 8px; }
.nav-group-title {
  /* 标题右侧拉到栏边：每组变成一条等宽的「带子」，比单纯加粗更像分区 */
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px 5px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--text-faint);
}
.nav-group-title::after {
  content: '';
  flex: 1 1 auto;
  height: 1px;
  background: var(--border-soft);
}
.nav-group-title.editing { cursor: grab; }
.nav-group-name { flex: none; }

.nav-edit-bar { display: flex; gap: 6px; padding: 0 6px 8px; }
.nav-edit-btn {
  flex: 1;
  height: 26px;
  border: 1px dashed var(--border);
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-dim);
  font-size: 11.5px;
  cursor: pointer;
}
.nav-edit-btn:hover { color: var(--text); border-color: var(--accent); }

.drag-dots {
  color: var(--text-faint);
  font-size: 11px;
  letter-spacing: -3px;
  padding: 0 2px 0 0;
  cursor: grab;
  touch-action: none;
  user-select: none;
}
.edit-item { cursor: default; }
.edit-item:hover { background: var(--bg-rail-hover); }
.nav-eye {
  margin-left: auto;
  border: 1px solid var(--border-soft);
  border-radius: 5px;
  background: transparent;
  color: var(--text-faint);
  font-size: 10.5px;
  height: 20px;
  padding: 0 5px;
  cursor: pointer;
}
.nav-eye:hover { color: var(--text); border-color: var(--border); }
.nav-mini {
  border: 1px solid var(--border-soft);
  border-radius: 5px;
  background: transparent;
  color: var(--text-faint);
  font-size: 10px;
  height: 18px;
  padding: 0 4px;
  cursor: pointer;
}
.nav-mini:hover { color: var(--text); border-color: var(--border); }
.edit-item.off { opacity: 0.45; }
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 34px;
  padding: 0 10px;
  border-radius: var(--radius-sm);
  color: var(--text-dim);
  font-size: 13px;
  text-decoration: none;
  white-space: nowrap;
  transition: background var(--dur-fast) var(--ease), color var(--dur-fast) var(--ease);
}
.nav-item:hover { background: var(--bg-rail-hover); color: var(--text); }
.nav-item.active { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
.nav-ico {
  display: inline-flex; font-size: 15px; flex: none; display: inline-flex; }
.nav-ico svg, .rail-foot-ico svg, .user-chip-caret svg, .hamburger svg {
  width: 1em;
  height: 1em;
  display: block;
}
.nav-label { overflow: hidden; text-overflow: ellipsis; }
.shell.collapsed .nav-item { width: 36px; padding: 0; justify-content: center; }

.rail-foot {
  flex: none;
  height: 42px;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 16px;
  border: none;
  border-top: 1px solid var(--rail-border);
  background: transparent;
  color: var(--text-faint);
  font-family: inherit;
  font-size: 12px;
  cursor: pointer;
  overflow: hidden;
  white-space: nowrap;
}
.rail-foot:hover { background: var(--bg-rail-hover); color: var(--text); }

/* 页面布局面板：固定在底部，任何页面都能用 */
.page-layout-panel {
  position: fixed;
  left: 50%;
  bottom: 14px;
  transform: translateX(-50%);
  width: min(560px, calc(100vw - 24px));
  max-height: 46vh;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--bg-card);
  box-shadow: 0 10px 30px rgb(0 0 0 / 0.16);
  z-index: 60;
}
.plp-head { display: flex; align-items: center; gap: 8px; }
.plp-title { font-size: 12.5px; font-weight: 600; }
.plp-hint { flex: 1; font-size: 11px; color: var(--text-faint); }
.plp-btn {
  border: 1px solid var(--border-soft);
  border-radius: 5px;
  background: transparent;
  color: var(--text-dim);
  height: 24px;
  padding: 0 8px;
  font-size: 11.5px;
  cursor: pointer;
}
.plp-btn:hover { color: var(--text); border-color: var(--border); }
.plp-btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.plp-list { overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
.plp-row { display: flex; align-items: center; gap: 6px; padding: 3px 4px; border-radius: 6px; }
.plp-row:hover { background: var(--bg-rail-hover); }
.plp-move {
  width: 26px;
  height: 24px;
  border: 1px solid var(--border-soft);
  border-radius: 5px;
  background: transparent;
  color: var(--text-dim);
  cursor: pointer;
}
.plp-label { flex: 1; font-size: 12px; color: var(--text-dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.shell.collapsed .rail-foot { justify-content: center; padding: 0; }

/* ---------- 主区 ---------- */
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }

.topbar {
  height: var(--topbar-h);
  flex: none;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 16px;
  background: var(--bg-card);
  border-bottom: 1px solid var(--rail-border);
}
.hamburger {
  display: none;
  width: 30px;
  height: 30px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-card);
  color: var(--text-dim);
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.topbar-account { width: 210px; max-width: 28vw; }
.title-block { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
.crumb { font-size: 10.5px; color: var(--text-faint); letter-spacing: 0.04em; }
.page-title {
  margin: 0;
  font-size: 15px;
  font-weight: 650;
  letter-spacing: -0.01em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.spacer { flex: 1; }

.uptime { display: inline-flex; align-items: center; gap: 6px; }
.dot { width: 6px; height: 6px; border-radius: 50%; background: var(--ok); display: inline-block; }
.dot.warn { background: var(--warn); }

.icon-btn {
  width: 30px;
  height: 30px;
  flex: none;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-card);
  display: grid;
  place-items: center;
  cursor: pointer;
  padding: 0;
}
.icon-btn:hover { border-color: var(--border-strong); background: var(--bg-hover); }
.accent-chip { width: 14px; height: 14px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.12); }

.user-chip {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 0 9px 0 4px;
  border: 1px solid var(--border);
  border-radius: var(--radius-full);
  background: var(--bg-card);
  color: var(--text);
  font-family: inherit;
  font-size: 12.5px;
  cursor: pointer;
}
.user-chip:hover { background: var(--bg-hover); }
.user-chip-name { max-width: 96px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.user-chip-caret { font-size: 11px; color: var(--text-faint); }

.content { flex: 1; overflow-y: auto; padding: 16px; background: var(--bg); }

.ui-progress {
  height: 2px;
  overflow: hidden;
  background: color-mix(in srgb, var(--accent) 16%, transparent);
}
.ui-progress i {
  display: block;
  height: 100%;
  width: 30%;
  background: var(--accent);
  animation: ui-progress 1.1s ease-in-out infinite;
}
@keyframes ui-progress {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(400%); }
}



.ui-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px;
  font-size: 12.5px;
  color: var(--danger, #d33);
  background: color-mix(in srgb, var(--danger, #d33) 8%, transparent);
  border-bottom: 1px solid color-mix(in srgb, var(--danger, #d33) 30%, transparent);
}
.ui-banner-text { flex: 1; user-select: text; }
.ui-banner-btn {
  padding: 3px 10px;
  border-radius: 4px;
  border: 1px solid currentColor;
  background: transparent;
  color: inherit;
  cursor: pointer;
  font-size: 12px;
}

.route-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  height: 40vh;
  color: var(--text-faint);
  font-size: 12.5px;
}
.route-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid color-mix(in srgb, var(--text-faint) 35%, transparent);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: route-spin 0.7s linear infinite;
}
@keyframes route-spin { to { transform: rotate(360deg); } }

/* ---------- 主题面板 ---------- */
.theme-panel {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: min(70vh, 560px);
  overflow-y: auto;
  margin: -2px;
  padding: 2px;
}
.tp-head { display: flex; align-items: center; justify-content: space-between; }
.tp-title { font-size: 13.5px; font-weight: 650; }
.tp-label {
  margin-top: 8px;
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-faint);
}
.tp-note { font-size: 10px; font-weight: 400; letter-spacing: 0; text-transform: none; }

.fw-list { display: flex; flex-direction: column; gap: 3px; }
.fw-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: transparent;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
  color: var(--text);
}
.fw-item:hover { background: var(--bg-hover); }
.fw-item.active { border-color: var(--accent-line); background: var(--accent-soft); }
.fw-item.busy { opacity: 0.72; cursor: progress; }
.fw-preview {
  width: 34px;
  height: 30px;
  flex: none;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: var(--bg-card-2);
  padding: 4px;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.fw-bar { height: 3px; border-radius: 2px; background: var(--border-strong); }
.fw-bar.short { width: 60%; }
.fw-btn { margin-top: auto; height: 7px; border-radius: 2px; background: var(--accent); width: 60%; }
.fw-antd .fw-btn { border-radius: 2px; }
.fw-naive .fw-btn { border-radius: 999px; width: 50%; }
.fw-arco .fw-btn { border-radius: 3px; width: 44%; }
.fw-vuetify .fw-btn { border-radius: 999px; height: 9px; width: 66%; }
.fw-text { display: flex; flex-direction: column; min-width: 0; }
.fw-name { font-size: 12.5px; font-weight: 550; }
.fw-hint { font-size: 10.5px; color: var(--text-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fw-tick { margin-left: auto; color: var(--accent); font-size: 12px; font-weight: 700; }
.fw-spin {
  margin-left: auto;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  border: 2px solid var(--accent-line);
  border-top-color: var(--accent);
  animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.mode-row { display: flex; gap: 4px; }
.mode-btn {
  flex: 1;
  height: 28px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-card);
  color: var(--text-dim);
  font-family: inherit;
  font-size: 12px;
  cursor: pointer;
}
.mode-btn:hover { color: var(--text); }
.mode-btn.active { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }

.swatches { display: grid; grid-template-columns: repeat(6, 1fr); gap: 7px; }
.swatch {
  aspect-ratio: 1;
  border: none;
  border-radius: var(--radius-sm);
  cursor: pointer;
  padding: 0;
  box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.08);
  transition: transform var(--dur-fast) var(--ease);
}
.swatch:hover { transform: translateY(-1px) scale(1.06); }
.swatch.active { box-shadow: 0 0 0 2px var(--bg-card), 0 0 0 4px var(--accent); }

.slider-row { display: grid; grid-template-columns: 28px 1fr 40px; align-items: center; gap: 10px; }
.slider-name { font-size: 11px; color: var(--text-faint); }
.slider-val { font-size: 10.5px; color: var(--text-dim); text-align: right; font-variant-numeric: tabular-nums; }
.range {
  -webkit-appearance: none;
  appearance: none;
  width: 100%;
  height: 18px;
  background: transparent;
  cursor: pointer;
}
.range::-webkit-slider-runnable-track {
  height: 4px;
  border-radius: 999px;
  background: var(--border);
}
.range::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 13px;
  height: 13px;
  margin-top: -4.5px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 0 2px var(--bg-card);
}
.range::-moz-range-track { height: 4px; border-radius: 999px; background: var(--border); }
.range::-moz-range-thumb { width: 13px; height: 13px; border: none; border-radius: 50%; background: var(--accent); }

.tp-foot { margin-top: 10px; display: flex; align-items: center; justify-content: space-between; }

.mobile-mask { display: none; }
.fade-enter-active,
.fade-leave-active { transition: opacity 0.16s var(--ease); }
.fade-enter-from,
.fade-leave-to { opacity: 0; }

@media (max-width: 860px) {
  .rail {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 210;
    transform: translateX(-100%);
    transition: transform var(--dur) var(--ease);
    box-shadow: var(--shadow-lg);
  }
  .rail.open { transform: translateX(0); }
  .shell.collapsed .rail { width: var(--sidebar-w); }
  .shell.collapsed .brand { padding: 0 14px; justify-content: flex-start; }
  .shell.collapsed .nav-item { width: auto; padding: 0 10px; justify-content: flex-start; }
  .rail-foot { display: none; }
  .hamburger { display: inline-flex; }
  .content { padding: 12px; }
  .mobile-mask { display: block; position: fixed; inset: 0; z-index: 200; background: rgb(15 17 21 / 0.42); }
  .user-chip { padding: 0 6px 0 3px; }
  .user-chip-name { display: none; }
  .uptime { display: none; }
}
</style>

<style>
/* ---------- 页面布局：内联编辑（与概览页同一套交互） ---------- */
.layout-inline-bar {
  display: flex;
  justify-content: flex-end;
}
.layout-inline-btn {
  height: 24px;
  padding: 0 11px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text-dim);
  font-size: 12px;
  cursor: pointer;
}
.layout-inline-btn:hover { color: var(--accent); border-color: var(--accent); }
.block-edit-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  /* 页面根节点是 gap:14 的 flex 列：负 margin 让操作条与区块之间保持 6px，和概览页一致 */
  margin-bottom: -8px;
  padding: 4px 8px;
  border: 1px dashed var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-card);
  color: var(--text-faint);
  font-size: 11.5px;
  user-select: none;
}
.be-grip { cursor: grab; letter-spacing: -2px; touch-action: none; user-select: none; }
.be-grip:active { cursor: grabbing; }
.be-name { color: var(--text-dim); }
.be-spacer { flex: 1; }
.be-btn {
  height: 20px;
  padding: 0 6px;
  border-radius: 5px;
  border: 1px solid var(--border-soft);
  background: transparent;
  color: var(--text-faint);
  font-size: 10.5px;
  cursor: pointer;
}
.be-btn:hover { color: var(--text); border-color: var(--border); }
.layout-block-off { opacity: 0.45; }
</style>