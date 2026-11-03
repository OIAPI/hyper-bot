import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { hslToHex } from '@/ui/theme-bridge'

export type ThemeMode = 'dark' | 'light' | 'auto'

/** 主题色预设：走「成熟后台」的实色路线，饱和度够高、色相干净，不再用灰扑扑的莫兰迪。 */
export interface ThemePreset {
  id: string
  name: string
  hint: string
  hue: number
  sat: number
  light: number
}

export const THEME_PRESETS: ThemePreset[] = [
  { id: 'moss', name: '苔绿', hint: 'moss', hue: 147, sat: 20, light: 33 },
  { id: 'pine', name: '松绿', hint: 'pine', hue: 163, sat: 26, light: 27 },
  { id: 'clay', name: '陶土', hint: 'clay', hue: 17, sat: 38, light: 44 },
  { id: 'ochre', name: '赭石', hint: 'ochre', hue: 36, sat: 38, light: 39 },
  { id: 'ink', name: '藏青', hint: 'ink', hue: 210, sat: 22, light: 31 },
  { id: 'mist', name: '雾蓝', hint: 'mist', hue: 206, sat: 20, light: 44 },
  { id: 'brick', name: '砖红', hint: 'brick', hue: 7, sat: 38, light: 41 },
  { id: 'plum', name: '梅子', hint: 'plum', hue: 347, sat: 24, light: 38 },
  { id: 'graphite', name: '石墨', hint: 'graphite', hue: 42, sat: 5, light: 26 },
]

/**
 * 界面框架：不是 CSS 抄外观，而是真的把整套组件库内置换掉。
 * 每个 id 对应 src/ui/adapters/<id>.ts 里的一份适配器，视图只认 UiXxx 抽象。
 */
export type FrameworkId = 'native' | 'element' | 'antd' | 'naive' | 'arco'

export interface Framework {
  id: FrameworkId
  name: string
  vendor: string
  hint: string
}

export const FRAMEWORKS: Framework[] = [
  { id: 'native', name: '轻量（无组件库）', vendor: '内置', hint: '几 KB 秒开 · 跟随主题 · 弱网/手机首选' },
  { id: 'element', name: 'Element Plus', vendor: 'Eleme', hint: '国内后台标准件 · 边框清晰 · 密度适中' },
  { id: 'antd', name: 'Ant Design', vendor: 'Ant Group', hint: '专业克制 · 灰底表头 · 14px 正文' },
  { id: 'naive', name: 'Naive UI', vendor: 'TuSimple', hint: '清爽留白 · 轻阴影 · 圆润控件' },
  { id: 'arco', name: 'Arco Design', vendor: 'ByteDance', hint: '锐利 · 高密度 · 细描边' },
]

const FW_KEY = 'hyper-framework'
export const DEFAULT_FRAMEWORK: FrameworkId = 'element'

const MODE_KEY = 'hyper-theme'
const HUE_KEY = 'hyper-hue'
const SAT_KEY = 'hyper-sat'
const LIGHT_KEY = 'hyper-light'
const PRESET_KEY = 'hyper-preset'

export const DEFAULT_PRESET = THEME_PRESETS[0]

/** 视觉版本号：改一次就重置一次外观偏好，避免老用户被旧配色（紫 268）钉死。 */
const STYLE_VERSION = 'v7'
const STYLE_KEYS = [MODE_KEY, HUE_KEY, SAT_KEY, LIGHT_KEY, PRESET_KEY, 'hyper-skin', FW_KEY]
try {
  if (localStorage.getItem('hyper-style-version') !== STYLE_VERSION) {
    for (const k of STYLE_KEYS) localStorage.removeItem(k)
    localStorage.setItem('hyper-style-version', STYLE_VERSION)
  }
} catch {
  // 隐私模式下 localStorage 可能抛异常，忽略
}

function readNumber(key: string, fallback: number): number {
  const raw = localStorage.getItem(key)
  if (raw === null || raw === '') return fallback
  const n = Number(raw)
  return Number.isFinite(n) ? n : fallback
}

export const useThemeStore = defineStore('theme', () => {
  const stored = localStorage.getItem(MODE_KEY)
  const mode = ref<ThemeMode>(stored === 'dark' || stored === 'auto' ? stored : 'light')
  const hue = ref(readNumber(HUE_KEY, DEFAULT_PRESET.hue))
  const sat = ref(readNumber(SAT_KEY, DEFAULT_PRESET.sat))
  const light = ref(readNumber(LIGHT_KEY, DEFAULT_PRESET.light))
  const presetId = ref(localStorage.getItem(PRESET_KEY) ?? DEFAULT_PRESET.id)
  /** URL 上的 ?fw=antd 优先，方便分享「某套框架」的直达链接 */
  function readFramework(): FrameworkId {
    const fromUrl = new URLSearchParams(location.search).get('fw')
    const candidate = fromUrl ?? localStorage.getItem(FW_KEY)
    return (FRAMEWORKS.find((f) => f.id === candidate)?.id ?? DEFAULT_FRAMEWORK) as FrameworkId
  }

  const framework = ref<FrameworkId>(readFramework())

  const prefersLight = window.matchMedia('(prefers-color-scheme: light)')
  const effective = computed<'dark' | 'light'>(() =>
    mode.value === 'auto' ? (prefersLight.matches ? 'light' : 'dark') : mode.value,
  )

  /** 当前主色（hex），适配器用它同步到各组件库的 primary token */
  const accentHex = computed(() => hslToHex(hue.value, sat.value, light.value))

  const activePreset = computed<ThemePreset | null>(
    () =>
      THEME_PRESETS.find(
        (p) =>
          p.hue === Math.round(hue.value) &&
          p.sat === Math.round(sat.value) &&
          p.light === Math.round(light.value),
      ) ?? null,
  )

  function apply(): void {
    const root = document.documentElement
    const dark = effective.value === 'dark'
    root.setAttribute('data-theme', effective.value)
    root.setAttribute('data-framework', framework.value)
    // Element Plus 认 html.dark
    root.classList.toggle('dark', dark)
    root.style.setProperty('--brand-h', String(Math.round(hue.value)))
    root.style.setProperty('--brand-s', `${Math.round(sat.value)}%`)
    root.style.setProperty('--brand-l', `${Math.round(light.value)}%`)
  }

  function setMode(next: ThemeMode): void {
    mode.value = next
    localStorage.setItem(MODE_KEY, next)
  }

  function setFramework(next: FrameworkId): void {
    if (!FRAMEWORKS.some((f) => f.id === next)) return
    framework.value = next
    localStorage.setItem(FW_KEY, next)
    // 手动切换后抹掉 URL 参数，否则刷新会被 ?fw= 覆盖回去
    const url = new URL(location.href)
    if (url.searchParams.has('fw')) {
      url.searchParams.delete('fw')
      history.replaceState(history.state, '', url.pathname + url.search + url.hash)
    }
  }

  function setAccent(nextHue: number, nextSat = sat.value, nextLight = light.value): void {
    hue.value = Math.max(0, Math.min(360, Math.round(nextHue)))
    sat.value = Math.max(0, Math.min(100, Math.round(nextSat)))
    light.value = Math.max(20, Math.min(80, Math.round(nextLight)))
    localStorage.setItem(HUE_KEY, String(hue.value))
    localStorage.setItem(SAT_KEY, String(sat.value))
    localStorage.setItem(LIGHT_KEY, String(light.value))
    const hit = THEME_PRESETS.find(
      (p) => p.hue === hue.value && p.sat === sat.value && p.light === light.value,
    )
    if (hit) {
      presetId.value = hit.id
      localStorage.setItem(PRESET_KEY, hit.id)
    }
  }

  function applyPreset(id: string): void {
    const p = THEME_PRESETS.find((item) => item.id === id)
    if (!p) return
    setAccent(p.hue, p.sat, p.light)
    presetId.value = p.id
    localStorage.setItem(PRESET_KEY, p.id)
  }

  function reset(): void {
    applyPreset(DEFAULT_PRESET.id)
    setFramework(DEFAULT_FRAMEWORK)
    setMode('light')
  }

  prefersLight.addEventListener('change', () => {
    if (mode.value === 'auto') apply()
  })

  watch([mode, hue, sat, light, framework], apply, { immediate: false })

  return {
    mode,
    framework,
    accentHex,
    hue,
    sat,
    light,
    presetId,
    effective,
    activePreset,
    apply,
    setMode,
    setFramework,
    setAccent,
    applyPreset,
    reset,
  }
})