<script setup lang="ts">
/**
 * 消息记录 · V6：全部控件走 UiXxx 抽象层。
 * 保留原能力：按日期取历史消息、关键字搜索（对整条记录做子串匹配）、刷新。
 */
import { computed, onMounted, ref } from 'vue'
import { logApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { formatTime } from '@/utils/format'
import type { UiColumn } from '@/ui/types'
import { sortRows, type SortState } from '@/utils/sortRows'
import type { MessageRecord } from '@/types/api'

const toast = useToastStore()

const items = ref<MessageRecord[]>([])
const loading = ref(true)
const dates = ref<string[]>([])
const selectedDate = ref('')
const keyword = ref('')

const cols: UiColumn[] = [
  { key: 'time', title: '时间', width: 176 },
  { key: 'source', title: '群 / 用户', width: 150 },
  { key: 'message', title: '内容', minWidth: 360 },
]

const dateOptions = computed(() => [
  { label: '最近', value: '' },
  ...dates.value.map((d) => ({ label: d, value: d })),
])

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await logApi.messages(selectedDate.value || undefined)
    items.value = res.items ?? []
    // 后端对「最近」会返回实际日期，顺手把下拉对齐，避免用户以为筛的是今天
    if (!selectedDate.value && res.date) {
      selectedDate.value = res.date
    }
    if (res.dates?.length) {
      dates.value = Array.from(new Set([...dates.value, ...res.dates])).sort().reverse()
    }
  } catch (e) {
    toast.fromError(e, '加载消息记录失败')
  } finally {
    loading.value = false
  }
}

async function loadDates(): Promise<void> {
  try {
    const res = await logApi.dates()
    dates.value = res.dates ?? []
  } catch {
    // 忽略
  }
}

onMounted(async () => {
  await Promise.all([load(), loadDates()])
})

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) return items.value
  return items.value.filter((m) => JSON.stringify(m).toLowerCase().includes(kw))
})

const sort = ref<SortState | null>(null)
const sorted = computed(() => sortRows(filtered.value, sort.value, {
  time: (m) => (m.time ? Number(m.time) : 0),
  source: (m) => m.group_id || m.user_id,
  content: (m) => m.raw_message,
}))

function messageTime(m: MessageRecord): string {
  return m.time ? formatTime(Number(m.time)) : '—'
}

function source(m: MessageRecord): string {
  return m.group_id || m.user_id || '—'
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>消息记录</h1>
        <p class="sub">按日期归档的上游消息原文，支持关键字过滤。</p>
      </div>
      <div class="row gap-8">
        <UiTag type="plain" size="small">{{ filtered.length }} 条</UiTag>
        <UiButton size="small" @click="load">刷新</UiButton>
      </div>
    </header>

    <UiCard>
      <div class="toolbar">
        <UiInput v-model="keyword" clearable placeholder="搜索消息内容…" class="w-kw" />
        <UiSelect v-model="selectedDate" :options="dateOptions" class="w-date" @change="load" />
      </div>

      <UiSkeleton v-if="loading" :rows="6" animated />

      <UiTable
        v-else
        :data="sorted"
        :sort="sort"
        :sort-keys="{ message: 'content' }"
        @update:sort="sort = $event"
        :columns="cols"
        max-height="clamp(240px, calc(100vh - 300px), 1400px)"
      >
        <template #cell-time="{ row }">
          <span class="cell-time">{{ messageTime(row) }}</span>
        </template>
        <template #cell-source="{ row }">
          <span class="cell-dim">{{ source(row) }}</span>
        </template>
        <template #cell-message="{ row }">
          <span class="cell-msg">{{ row.raw_message || '—' }}</span>
        </template>
        <template #empty>
          <UiEmpty description="暂无消息记录" />
        </template>
      </UiTable>
    </UiCard>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { margin-bottom: 0; align-items: flex-end; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }

.row { display: flex; align-items: center; }
.gap-8 { gap: 8px; }

.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.w-kw { flex: 1 1 220px; max-width: 280px; }
.w-date { width: 170px; }

.cell-time { font-family: var(--font-mono); font-size: 12px; color: var(--text-faint); }
.cell-dim { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
.cell-msg {
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.55;
  color: var(--text-dim);
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 620px) {
  .w-kw, .w-date { flex: 1 1 100%; width: 100%; max-width: none; }
}
</style>