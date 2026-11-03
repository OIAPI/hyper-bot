<script setup lang="ts">
/**
 * 日志审查 · V6：全部控件走 UiXxx 抽象层（切换组件库时整页跟着变）。
 * 保留旧版全部能力：级别过滤、关键字搜索、按日期翻历史、自动刷新与导出。
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { logApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import type { UiColumn } from '@/ui/types'
import type { LogEntry } from '@/types/api'

const toast = useToastStore()

const entries = ref<LogEntry[]>([])
const loading = ref(true)
const autoRefresh = ref(true)
const level = ref('')
const keyword = ref('')
const dates = ref<string[]>([])
const selectedDate = ref('')

let timer = 0

const LEVELS = [
  { value: '', label: '全部级别' },
  { value: 'ERROR', label: 'ERROR' },
  { value: 'WARNING', label: 'WARNING' },
  { value: 'INFO', label: 'INFO' },
  { value: 'SUCCESS', label: 'SUCCESS' },
  { value: 'DEBUG', label: 'DEBUG' },
]

const cols: UiColumn[] = [
  { key: 'time', title: '时间', width: 112 },
  { key: 'level', title: '级别', width: 104 },
  { key: 'channel', title: '通道', width: 150 },
  { key: 'message', title: '内容', minWidth: 360 },
]

const dateOptions = computed(() => [
  { label: '实时（今日）', value: '' },
  ...dates.value.map((d) => ({ label: d, value: d })),
])

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = selectedDate.value
      ? await logApi.day(selectedDate.value)
      : await logApi.tail(400)
    entries.value = res.lines ?? []
  } catch (e) {
    toast.fromError(e, '读取日志失败')
  } finally {
    loading.value = false
  }
}

async function loadDates(): Promise<void> {
  try {
    const res = await logApi.dates()
    dates.value = res.dates ?? res.items ?? []
  } catch {
    // 没有历史日志不算错误
  }
}

/** 轮询只在「实时」模式且未选历史日期时进行 */
async function poll(): Promise<void> {
  if (!autoRefresh.value || selectedDate.value) return
  try {
    const res = await logApi.tail(400)
    entries.value = res.lines ?? []
  } catch {
    // 静默
  }
}

onMounted(async () => {
  await Promise.all([load(), loadDates()])
  timer = window.setInterval(poll, 4000)
})

onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})

watch(selectedDate, load)

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  return entries.value.filter((e) => {
    if (level.value && (e.level ?? '').toUpperCase() !== level.value) return false
    if (!kw) return true
    const hay = (e.message + ' ' + (e.channel ?? '') + ' ' + JSON.stringify(e.context ?? '')).toLowerCase()
    return hay.includes(kw)
  })
})

const levelCounts = computed(() => {
  const map: Record<string, number> = {}
  for (const e of entries.value) {
    const k = (e.level ?? 'UNKNOWN').toUpperCase()
    map[k] = (map[k] ?? 0) + 1
  }
  return map
})

/** 表格时间列只保留时分秒，和旧版展示一致 */
function shortTime(t: string): string {
  return (t ?? '').replace(/^\d{4}-\d{2}-\d{2}[ T]/, '')
}

/** Element 的语义名映射到 UiTag 的语义名 */
function levelTagType(lv: string): 'danger' | 'warn' | 'ok' | 'accent' | 'info' {
  switch ((lv ?? '').toUpperCase()) {
    case 'ERROR':
      return 'danger'
    case 'WARNING':
    case 'WARN':
      return 'warn'
    case 'SUCCESS':
      return 'ok'
    case 'INFO':
      return 'accent'
    default:
      return 'info'
  }
}

function exportLogs(): void {
  const text = filtered.value
    .map((e) => `[${e.time}] [${e.level}] [${e.channel}] ${e.message}`)
    .join('\n')
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'hyper-logs-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.log'
  a.click()
  URL.revokeObjectURL(url)
  toast.ok(`已导出 ${filtered.value.length} 条`)
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>日志</h1>
        <p class="sub">实时日志流与历史归档，支持按级别、关键字与日期筛选。</p>
      </div>
      <div class="row gap-8">
        <UiButton size="small" @click="load">刷新</UiButton>
        <UiButton size="small" @click="exportLogs">导出</UiButton>
      </div>
    </header>

    <UiCard>
      <div class="toolbar">
        <div class="row gap-8 wrap">
          <UiTag type="plain">{{ entries.length }} 条</UiTag>
          <span class="meta">{{ filtered.length }} 条匹配</span>
        </div>
        <div class="spacer" />
        <UiInput v-model="keyword" clearable placeholder="搜索关键字…" class="w-kw" />
        <UiSelect v-model="level" :options="LEVELS" class="w-level" />
        <UiSelect v-model="selectedDate" :options="dateOptions" class="w-date" />
        <div class="row gap-8">
          <UiSwitch v-model="autoRefresh" />
          <span class="meta">{{ autoRefresh ? '自动刷新' : '已暂停' }}</span>
        </div>
      </div>

      <div v-if="Object.keys(levelCounts).length" class="level-bar">
        <UiTag
          v-for="(n, lv) in levelCounts"
          :key="lv"
          :type="levelTagType(String(lv))"
          size="small"
        >
          {{ lv }} {{ n }}
        </UiTag>
      </div>
    </UiCard>

    <UiCard v-if="loading">
      <UiSkeleton :rows="8" animated />
    </UiCard>

    <UiCard v-else padding="none">
      <UiTable :data="filtered" :columns="cols" max-height="clamp(260px, calc(100vh - 400px), 1400px)">
        <template #cell-time="{ row }">
          <span class="cell-time" :title="row.time">{{ shortTime(row.time) }}</span>
        </template>
        <template #cell-level="{ row }">
          <UiTag :type="levelTagType(row.level)" size="small">{{ row.level || 'UNKNOWN' }}</UiTag>
        </template>
        <template #cell-channel="{ row }">
          <span class="cell-channel">{{ row.channel || '—' }}</span>
        </template>
        <template #cell-message="{ row }">
          <span class="cell-msg">{{ row.message }}</span>
        </template>
        <template #empty>
          <UiEmpty description="没有匹配的日志" />
        </template>
      </UiTable>
    </UiCard>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { align-items: flex-end; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }

.row { display: flex; align-items: center; }
.gap-8 { gap: 8px; }
.wrap { flex-wrap: wrap; }
.spacer { flex: 1; }
.meta { font-size: 11.5px; color: var(--text-faint); }

.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.w-kw { flex: 1 1 220px; max-width: 280px; }
.w-level { width: 150px; }
.w-date { width: 170px; }

.level-bar { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }

.cell-time { font-family: var(--font-mono); font-size: 12px; color: var(--text-faint); }
.cell-channel { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
.cell-msg {
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.55;
  color: var(--text-dim);
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 620px) {
  .w-kw, .w-level, .w-date { flex: 1 1 100%; width: 100%; max-width: none; }
}
</style>