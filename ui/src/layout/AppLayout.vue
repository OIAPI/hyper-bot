<script setup lang="ts">
/**
 * 应用外壳 · V6「框架无关」
 * 三件事各自独立，且都不刷新页面：
 *  - 组件库（data-framework + 懒加载适配器）：控件几何、层次、字重整体切换
 *  - 明暗（data-theme / html.dark）
 *  - 主题色（--brand-h/s/l 派生 --accent，适配器 applyTheme 再同步给各组件库）
 * 导航与外壳用自有标记 + CSS，不做成组件库控件——它是品牌骨架，切换框架时不应跳动。
 */
import { ref, computed, nextTick, onMounted, onUnmounted, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Odometer, Connection, Download, Document, ChatDotRound, Grid, Box,
  Tickets, Setting, Cpu, Tools, User, Fold, Expand, Menu as MenuIcon, ArrowDown,
} from '@element-plus/icons-vue'
import {
  useThemeStore, THEME_PRESETS, FRAMEWORKS, type ThemeMode, type FrameworkId,
} from '@/stores/theme'
import { useUiRuntime, loadFramework } from '@/ui/provider'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { overviewApi, authApi } from '@/api'
import { formatUptime } from '@/utils/format'
import AppToast from '@/components/AppToast.vue'

const route = useRoute()
const router = useRouter()
const theme = useThemeStore()
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
  account: User,
  'ui-kit': Grid,
}

const navGroups = computed(() => {
  const groups = new Map<string, Array<{ path: string; title: string; name: string }>>()
  for (const r of router.getRoutes()) {
    const meta = r.meta as { title?: string; icon?: string; group?: string }
    if (!meta.title || !meta.group || !r.name) continue
    const path = r.path === '' ? '/' : r.path.startsWith('/') ? r.path : '/' + r.path
    const list = groups.get(meta.group) ?? []
    list.push({ path, title: meta.title, name: String(r.name) })
    groups.set(meta.group, list)
  }
  return [...groups.entries()].map(([name, items]) => ({ name, items }))
})

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

onMounted(async () => {
  syncNarrow()
  mq.addEventListener('change', syncNarrow)
  await auth.loadMe()
  void refreshStatus()
  timer = window.setInterval(refreshStatus, 30_000)
})

onUnmounted(() => {
  if (timer) window.clearInterval(timer)
  mq.removeEventListener('change', syncNarrow)
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
        <div v-for="g in navGroups" :key="g.name" class="nav-group">
          <div v-if="!menuCollapsed" class="nav-group-title">{{ g.name }}</div>
          <RouterLink
            v-for="item in g.items"
            :key="item.name"
            :to="item.path"
            class="nav-item"
            :class="{ active: activePath === item.path }"
            :title="menuCollapsed ? item.title : undefined"
            @click="mobileNav = false"
          >
            <span class="nav-ico"><component :is="NAV_ICONS[item.name] ?? Grid" /></span>
            <span v-if="!menuCollapsed" class="nav-label">{{ item.title }}</span>
          </RouterLink>
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
          <button class="account" type="button">
            <UiAvatar :size="26" class="avatar">{{ (auth.user?.username ?? '?').slice(0, 1).toUpperCase() }}</UiAvatar>
            <span class="account-name">{{ auth.user?.username ?? '—' }}</span>
            <span class="account-caret"><ArrowDown /></span>
          </button>
        </UiDropdown>
      </header>

      <div v-if="uiLoading && !uiFailed" class="ui-progress"><i /></div>

      <div v-if="uiFailed" class="ui-banner">
        <span class="ui-banner-text">界面组件库加载失败：{{ uiFailed }}</span>
        <button type="button" class="ui-banner-btn" @click="retryUi">重试</button>
      </div>

      <div class="content">
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
.nav-group + .nav-group { margin-top: 10px; }
.nav-group-title {
  padding: 4px 10px 5px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--text-faint);
}
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
.nav-ico svg, .rail-foot-ico svg, .account-caret svg, .hamburger svg {
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

.account {
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
.account:hover { background: var(--bg-hover); }
.account-name { max-width: 96px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.account-caret { font-size: 11px; color: var(--text-faint); }

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
  .account { padding: 0 6px 0 3px; }
  .account-name { display: none; }
  .uptime { display: none; }
}
</style>