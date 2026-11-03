<script setup lang="ts">
/**
 * 事件读取 · V6：全部控件走 UiXxx 抽象层。
 * 数据两路：overviewApi.events() 拿事件流，metrics store 的采样桶算速率；
 * 保留原能力：类型过滤、完整 JSON 详情、自动刷新。
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { overviewApi } from '@/api'
import { useMetricsStore } from '@/stores/metrics'
import { useToastStore } from '@/stores/toast'
import { formatRelative, formatTime } from '@/utils/format'
import StreamChart from '@/components/StreamChart.vue'
import type { UiColumn } from '@/ui/types'
import type { TimelineEvent } from '@/types/api'

const toast = useToastStore()
const metrics = useMetricsStore()

/** 表格 scoped slot 只给到普通对象，这里收敛成具体类型 */
function asEvent(row: unknown): TimelineEvent {
  return row as TimelineEvent
}

const events = ref<TimelineEvent[]>([])
const loading = ref(true)
const filter = ref('')
const live = ref(true)
const detail = ref<TimelineEvent | null>(null)

let timer = 0
let release: (() => void) | null = null

const cols: UiColumn[] = [
  { key: 'type', title: '类型', width: 110 },
  { key: 'source', title: '来源', width: 184, ellipsis: true },
  { key: 'message', title: '内容', minWidth: 320, ellipsis: true },
  { key: 'received', title: '时间', width: 120 },
  { key: 'actions', title: '', width: 84, align: 'right' },
]

async function load(): Promise<void> {
  try {
    events.value = await overviewApi.events(200, filter.value || undefined)
  } catch (e) {
    toast.fromError(e, '加载事件失败')
  } finally {
    loading.value = false
  }
}

async function poll(): Promise<void> {
  if (!live.value) return
  try {
    events.value = await overviewApi.events(200, filter.value || undefined)
  } catch {
    // 静默
  }
}

onMounted(async () => {
  await load()
  release = metrics.acquire()
  timer = window.setInterval(poll, 5000)
})

onUnmounted(() => {
  release?.()
  if (timer) window.clearInterval(timer)
})

const TYPES = [
  { value: '', label: '全部事件' },
  { value: 'message', label: '消息 message' },
  { value: 'notice', label: '通知 notice' },
  { value: 'request', label: '请求 request' },
  { value: 'meta_event', label: '元事件 meta_event' },
]

/** 详情弹窗：detail 为 null 即关闭，避免再维护一个布尔量 */
const detailOpen = computed({
  get: () => detail.value !== null,
  set: (open: boolean) => {
    if (!open) detail.value = null
  },
})

const bucketSeries = computed(() => metrics.buckets.map((b) => b.open))
const recentOpen = computed(() => bucketSeries.value[bucketSeries.value.length - 1] ?? 0)
const peakOpen = computed(() => (bucketSeries.value.length ? Math.max(...bucketSeries.value) : 0))

function tone(e: TimelineEvent): 'accent' | 'warn' | 'danger' | 'info' {
  if (e.post_type === 'message') return 'accent'
  if (e.post_type === 'notice') return 'warn'
  if (e.post_type === 'request') return 'danger'
  return 'info'
}

function summary(e: TimelineEvent): string {
  const bits: string[] = []
  if (e.group_id) bits.push('群 ' + e.group_id)
  if (e.user_id) bits.push('用户 ' + e.user_id)
  if (e.notice_type) bits.push(e.notice_type)
  if (e.sub_type) bits.push(e.sub_type)
  return bits.join(' · ')
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>事件</h1>
        <p class="sub">上游报文的采样速率与完整事件流，可展开查看原始 JSON。</p>
      </div>
    </header>

    <UiCard title="速率桶">
      <template #extra>
        <span class="meta">每 3 秒采样</span>
      </template>

      <template v-if="bucketSeries.length">
        <div class="bucket-readout">
          <div class="bucket-stat">
            <span class="bstat-v">{{ recentOpen }}</span>
            <span class="bstat-k">最近桶新增</span>
          </div>
          <span class="vsep" />
          <div class="bucket-stat">
            <span class="bstat-v">{{ peakOpen }}</span>
            <span class="bstat-k">单桶峰值</span>
          </div>
          <span class="vsep" />
          <div class="bucket-stat">
            <span class="bstat-v">{{ metrics.windowEvents }}</span>
            <span class="bstat-k">窗口累计</span>
          </div>
        </div>
        <StreamChart :values="bucketSeries" :height="120" />
      </template>
      <UiEmpty v-else description="暂无数据" />

      <UiAlert
        v-if="metrics.error"
        type="warn"
        :title="'采样异常：' + metrics.error"
        :closable="false"
        class="mt-12"
      />
    </UiCard>

    <UiCard title="事件流">
      <template #extra>
        <UiTag type="plain" size="small">{{ events.length }} 条</UiTag>
      </template>

      <div class="toolbar">
        <UiSelect v-model="filter" :options="TYPES" class="w-filter" @change="load" />
        <div class="row gap-8">
          <UiSwitch v-model="live" />
          <span class="meta">{{ live ? '实时' : '已暂停' }}</span>
        </div>
        <div class="spacer" />
        <UiButton size="small" @click="load">刷新</UiButton>
      </div>

      <UiSkeleton v-if="loading" :rows="6" animated />

      <UiTable
        v-else
        :data="events"
        :columns="cols"
        max-height="clamp(240px, calc(100vh - 520px), 1200px)"
      >
        <template #cell-type="{ row }">
          <UiTag :type="tone(asEvent(row))" size="small">{{ row.post_type }}</UiTag>
        </template>
        <template #cell-source="{ row }">
          <span class="cell-dim">{{ summary(asEvent(row)) || '—' }}</span>
        </template>
        <template #cell-message="{ row }">
          <span class="cell-msg">{{ row.raw_message || '—' }}</span>
        </template>
        <template #cell-received="{ row }">
          <UiTooltip :content="formatTime(row.received)" placement="top">
            <span class="cell-dim">{{ formatRelative(row.received) }}</span>
          </UiTooltip>
        </template>
        <template #cell-actions="{ row }">
          <UiButton size="small" type="text" @click="detail = asEvent(row)">详情</UiButton>
        </template>
        <template #empty>
          <UiEmpty description="暂无事件" />
        </template>
      </UiTable>
    </UiCard>

    <UiDialog v-model="detailOpen" title="事件详情" width="680px">
      <pre class="json">{{ detail ? JSON.stringify(detail, null, 2) : '' }}</pre>
    </UiDialog>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { margin-bottom: 0; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }

.row { display: flex; align-items: center; gap: 8px; }
.gap-8 { gap: 8px; }
.spacer { flex: 1; }
.meta { font-size: 11.5px; color: var(--text-faint); }

.bucket-readout { display: flex; align-items: center; gap: 16px; margin-bottom: 6px; }
.bucket-stat { display: flex; flex-direction: column; }
.vsep { width: 1px; align-self: stretch; min-height: 28px; background: var(--border-soft); }
.bstat-v {
  font-size: 21px;
  font-weight: 660;
  line-height: 1.2;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}
.bstat-k { font-size: 11px; color: var(--text-faint); }

.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.w-filter { width: 178px; }

.cell-dim { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
.cell-msg {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--text-dim);
}

.json {
  margin: 0;
  max-height: 60vh;
  overflow: auto;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.6;
  color: var(--text-dim);
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 620px) {
  .w-filter { flex: 1 1 100%; width: 100%; }
}
</style>
