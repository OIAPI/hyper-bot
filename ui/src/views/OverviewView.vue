<script setup lang="ts">
/**
 * 概览页 · V6：框架无关。
 * 数据两路：overviewApi.index() 拿静态信息，metrics store 每 3s 采样驱动图表；
 * 事件流复用同一心跳，不额外压服务端。
 * 视觉：贴近「轻」——少阴影、多留白、数字用等宽字形、只用一条强调色。
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ChatDotRound, Connection, Cpu, DataLine, Refresh, VideoPause, VideoPlay } from '@element-plus/icons-vue'
import { overviewApi } from '@/api'
import { useMetricsStore } from '@/stores/metrics'
import { useToastStore } from '@/stores/toast'
import { formatBytes, formatRelative, formatUptime } from '@/utils/format'
import StreamChart from '@/components/StreamChart.vue'
import LayoutBlock from '@/components/LayoutBlock.vue'
import { loadPageLayout, savePageLayout, type PageLayoutPrefs } from '@/layout/pageLayout'
import ExtWidgets from '@/components/ExtWidgets.vue'
import type { Overview, ProtocolStatus, TimelineEvent } from '@/types/api'

const toast = useToastStore()
const metrics = useMetricsStore()
const route = useRoute()

/** 页面区块：id 用于排序/隐藏，label 给「页面布局」弹窗显示 */
const BLOCKS = [
  { id: 'banner', label: '状态横幅' },
  { id: 'kpis', label: '关键指标' },
  { id: 'chart', label: '实时流量' },
  { id: 'system', label: '健康检查 · 运行环境' },
  { id: 'traffic', label: '协议连接 · 实时事件' },
  { id: 'widgets', label: '插件注入块' },
]
/** 布局编辑模式：开启后每块前面出现拖动柄和隐藏按钮 */
const layoutEdit = ref(false)
const layout = ref<PageLayoutPrefs>(loadPageLayout(route.path))
const draggingBlock = ref('')
const narrow = ref(false)
const mq = window.matchMedia('(max-width: 860px)')
function syncNarrow(): void {
  narrow.value = mq.matches
}

function fullOrder(): string[] {
  const ids = BLOCKS.map((block) => block.id)
  const order = layout.value.order.filter((id) => ids.includes(id))
  for (const id of ids) if (!order.includes(id)) order.push(id)
  return order
}

function blockStyle(id: string): Record<string, string | number | undefined> {
  const order = fullOrder()
  return {
    order: order.indexOf(id),
    display: layout.value.hidden.includes(id) && !layoutEdit.value ? 'none' : undefined,
  }
}

function persistLayout(): void {
  savePageLayout(route.path, { order: fullOrder(), hidden: [...layout.value.hidden] })
}

function moveBlock(id: string, delta: number): void {
  const order = fullOrder()
  const index = order.indexOf(id)
  const target = index + delta
  if (index === -1 || target < 0 || target >= order.length) return
  order.splice(index, 1)
  order.splice(target, 0, id)
  layout.value = { ...layout.value, order }
  persistLayout()
}

function toggleBlock(id: string): void {
  const hidden = new Set(layout.value.hidden)
  if (hidden.has(id)) hidden.delete(id)
  else hidden.add(id)
  layout.value = { ...layout.value, hidden: [...hidden] }
  persistLayout()
}

function startBlockDrag(event: PointerEvent, id: string): void {
  if (!layoutEdit.value) return
  event.preventDefault()
  draggingBlock.value = id
  window.addEventListener('pointermove', onBlockDrag)
  window.addEventListener('pointerup', stopBlockDrag, { once: true })
  window.addEventListener('pointercancel', stopBlockDrag, { once: true })
}

function onBlockDrag(event: PointerEvent): void {
  const id = draggingBlock.value
  if (!id) return
  const el = document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null
  const target = el?.closest<HTMLElement>('[data-block]')?.dataset.block
  if (!target || target === id) return
  const order = fullOrder()
  const from = order.indexOf(id)
  const to = order.indexOf(target)
  if (from === -1 || to === -1) return
  order.splice(from, 1)
  order.splice(to, 0, id)
  layout.value = { ...layout.value, order }
  persistLayout()
}

function stopBlockDrag(): void {
  draggingBlock.value = ''
  window.removeEventListener('pointermove', onBlockDrag)
}

const data = ref<Overview | null>(null)
const loading = ref(true)
const events = ref<TimelineEvent[]>([])
const chartTab = ref<'events' | 'messages'>('events')
const paused = ref(false)

let release: (() => void) | null = null
let eventTimer = 0

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await overviewApi.index()
    events.value = data.value.recent ?? []
  } catch (e) {
    toast.fromError(e, '加载概览失败')
  } finally {
    loading.value = false
  }
}

async function pollEvents(): Promise<void> {
  if (paused.value) return
  try {
    events.value = await overviewApi.events(18)
  } catch {
    // 实时面板不该因为一次网络抖动就弹错误
  }
}

onMounted(async () => {
  syncNarrow()
  mq.addEventListener('change', syncNarrow)
  await load()
  release = metrics.acquire()
  eventTimer = window.setInterval(pollEvents, 5000)
})

onUnmounted(() => {
  mq.removeEventListener('change', syncNarrow)
  release?.()
  if (eventTimer) window.clearInterval(eventTimer)
})

function toggleLive(): void {
  paused.value = !paused.value
  metrics.setLive(!paused.value)
}

const counts = computed(() => metrics.lastCounts ?? data.value?.counts ?? null)
const protocols = computed<Array<[string, ProtocolStatus]>>(() =>
  Object.entries(data.value?.protocol ?? {}),
)
const enabledProtocols = computed(() => protocols.value.filter(([, p]) => p.enabled).length)

const chartOptions = [
  { label: '事件速率', value: 'events' },
  { label: '消息速率', value: 'messages' },
]

const activeSeries = computed(() =>
  chartTab.value === 'events' ? metrics.eventRateSeries : metrics.messageRateSeries,
)
const activeRate = computed(() =>
  chartTab.value === 'events' ? metrics.currentRate : metrics.currentMessageRate,
)
const chartColor = computed(() => (chartTab.value === 'events' ? 'var(--accent)' : 'var(--info)'))
const chartUnit = computed(() => (chartTab.value === 'events' ? '事件' : '消息'))
const peakRate = computed(() => {
  const s = activeSeries.value
  return s.length ? Math.max(...s) : 0
})

const healthPct = computed(() =>
  metrics.totalChecks ? Math.round((metrics.okChecks / metrics.totalChecks) * 100) : 100,
)
const diskPct = computed(() => {
  const d = metrics.disk
  if (!d || !d.total) return 0
  return Math.round((d.used / d.total) * 100)
})

const windowLabel = computed(() => {
  const n = metrics.samples.length
  if (n < 2) return '刚刚开始采样'
  return '近 ' + Math.max(1, Math.round(((n - 1) * 3) / 60)) + ' 分钟'
})

const uptimeText = computed(() => (data.value ? formatUptime(data.value.runtime.uptime) : '—'))

const kpis = computed(() => {
  const c = counts.value
  return [
    { key: 'groups', label: '群组', value: c?.groups ?? '—', icon: Connection, tone: 'accent' },
    { key: 'plugins', label: '插件', value: c ? (c.plugins_on ?? c.plugins) + '/' + c.plugins : '—', icon: Cpu, tone: 'ok' },
    { key: 'events', label: '累计事件', value: c?.events ?? '—', icon: DataLine, tone: 'info' },
    { key: 'messages', label: '累计消息', value: c?.messages ?? '—', icon: ChatDotRound, tone: 'warn' },
  ]
})

const envItems = computed(() => {
  const a = data.value?.app
  if (!a) return []
  return [
    { label: 'PHP', value: a.php },
    { label: 'Swoole', value: a.swoole ?? '未安装' },
    { label: '存储', value: a.store },
    { label: '时区', value: a.timezone },
    { label: '调试', value: a.debug ? '开启' : '关闭' },
  ]
})

/** 协议详情里挑一个最值得显示的状态值 */
function protocolHint(p: ProtocolStatus): string {
  const d = p.detail ?? {}
  if (p.label.includes('OneBot')) {
    if (d.forward_running) return '正向 WS 已连接'
    if ((d.reverse_sessions ?? 0) > 0) return '反向会话 ' + d.reverse_sessions
    return '无 WS 连接，走 HTTP'
  }
  if (p.label === 'Milky') return d.api ? String(d.api) : '未配置 API 地址'
  if (p.label.includes('QQ')) return d.app_id ? 'AppID ' + d.app_id : '未配置 AppID'
  return ''
}

function eventTag(e: TimelineEvent): { type: 'accent' | 'warn' | 'danger' | 'info'; text: string } {
  if (e.post_type === 'message') return { type: 'accent', text: '消息' }
  if (e.post_type === 'notice') return { type: 'warn', text: '通知' }
  if (e.post_type === 'request') return { type: 'danger', text: '请求' }
  return { type: 'info', text: '元事件' }
}

function eventTitle(e: TimelineEvent): string {
  if (e.post_type === 'message') {
    const scope = e.message_type === 'group' ? '群 ' + e.group_id : '私聊 ' + e.user_id
    return scope + ' · ' + (e.user_id || '')
  }
  if (e.post_type === 'notice') return (e.notice_type ?? '') + ' ' + (e.group_id || e.user_id || '')
  if (e.post_type === 'request') return (e.sub_type ?? '') + ' 请求 · ' + (e.user_id ?? '')
  return e.post_type
}
</script>

<template>
  <div class="page-wrap">
    <div class="layout-bar">
      <UiButton size="small" @click="layoutEdit = !layoutEdit">
        {{ layoutEdit ? "完成" : "编辑布局" }}
      </UiButton>
    </div>

  <div class="dash">
    <div v-if="loading" class="kpi-grid">
      <div v-for="i in 4" :key="i" class="kpi-card">
        <UiSkeleton :rows="2" animated />
      </div>
    </div>

    <template v-else-if="data">
      <!-- 状态横幅：一条扁平信息带，不用卡片包 -->
      <LayoutBlock
        id="banner"
        label="状态横幅"
        :editing="layoutEdit"
        :hidden="layout.hidden.includes('banner')"
        :narrow="narrow"
        @move-up="moveBlock('banner', -1)"
        @move-down="moveBlock('banner', 1)"
        :style="blockStyle('banner')"
        @drag-start="startBlockDrag($event, 'banner')"
        @toggle="toggleBlock('banner')"
      >
      <section class="banner">
        <div class="banner-left">
          <div class="banner-title">
            <span class="live">
              <span class="dot" :class="paused ? '' : 'on'" />
              {{ paused ? '已暂停' : '实时' }}
            </span>
            <span class="banner-h">服务运行中</span>
          </div>
          <div class="banner-sub">
            {{ data.app.php }} · Swoole {{ data.app.swoole ?? '未安装' }} · 已运行 {{ uptimeText }}
          </div>
        </div>

        <div class="banner-stats">
          <div class="bstat">
            <div class="bstat-v">{{ activeRate }}</div>
            <div class="bstat-k">{{ chartUnit }}/分钟</div>
          </div>
          <div class="bstat">
            <div class="bstat-v">{{ peakRate }}</div>
            <div class="bstat-k">峰值</div>
          </div>
          <div class="bstat">
            <div class="bstat-v">{{ metrics.currentLatency }}<em>ms</em></div>
            <div class="bstat-k">接口延迟</div>
          </div>
        </div>

        <div class="banner-actions">
          <UiButton size="small" @click="toggleLive">
            <template #icon>
              <UiIcon><component :is="paused ? VideoPlay : VideoPause" /></UiIcon>
            </template>
            {{ paused ? '恢复' : '暂停' }}
          </UiButton>
          <UiButton size="small" type="primary" @click="load">
            <template #icon><UiIcon><Refresh /></UiIcon></template>
            刷新
          </UiButton>
        </div>
      </section>
      </LayoutBlock>

      <!-- KPI -->
      <LayoutBlock
        id="kpis"
        label="关键指标"
        :editing="layoutEdit"
        :hidden="layout.hidden.includes('kpis')"
        :narrow="narrow"
        @move-up="moveBlock('kpis', -1)"
        @move-down="moveBlock('kpis', 1)"
        :style="blockStyle('kpis')"
        @drag-start="startBlockDrag($event, 'kpis')"
        @toggle="toggleBlock('kpis')"
      >
      <div class="kpi-grid">
        <div v-for="k in kpis" :key="k.key" class="kpi-card">
          <div class="kpi-icon" :class="'tone-' + k.tone">
            <UiIcon><component :is="k.icon" /></UiIcon>
          </div>
          <div class="kpi-text">
            <div class="kpi-label">{{ k.label }}</div>
            <div class="kpi-value">{{ k.value }}</div>
          </div>
        </div>
      </div>
      </LayoutBlock>

      <!-- 实时流量 -->
      <LayoutBlock
        id="chart"
        label="实时流量"
        :editing="layoutEdit"
        :hidden="layout.hidden.includes('chart')"
        :narrow="narrow"
        @move-up="moveBlock('chart', -1)"
        @move-down="moveBlock('chart', 1)"
        :style="blockStyle('chart')"
        @drag-start="startBlockDrag($event, 'chart')"
        @toggle="toggleBlock('chart')"
      >
      <UiCard>
        <template #header>
          <div class="card-head-row">
            <span class="card-title">实时流量</span>
            <div class="spacer" />
            <UiSegmented v-model="chartTab" :options="chartOptions" />
          </div>
        </template>
        <div class="chart-readout">
          <span class="chart-value" :style="{ color: chartColor }">{{ activeRate }}</span>
          <span class="chart-unit">{{ chartUnit }} / 分钟</span>
          <div class="spacer" />
          <span class="chart-meta">窗口 {{ windowLabel }} · 每 3 秒采样</span>
        </div>
        <StreamChart
          :values="activeSeries.length ? activeSeries : [0, 0]"
          :color="chartColor"
          :height="164"
        />
        <UiAlert v-if="metrics.error" type="warn" :title="'采样异常：' + metrics.error" />
      </UiCard>
      </LayoutBlock>

      <LayoutBlock
        id="system"
        label="健康检查 · 运行环境"
        :editing="layoutEdit"
        :hidden="layout.hidden.includes('system')"
        :narrow="narrow"
        @move-up="moveBlock('system', -1)"
        @move-down="moveBlock('system', 1)"
        :style="blockStyle('system')"
        @drag-start="startBlockDrag($event, 'system')"
        @toggle="toggleBlock('system')"
      >
      <div class="two-col">
        <!-- 健康度 -->
        <UiCard>
          <template #header><span class="card-title">健康检查</span></template>
          <div class="health">
            <UiProgress
              type="circle"
              :percentage="healthPct"
              :width="84"
              :stroke-width="7"
              :color="healthPct >= 90 ? 'var(--ok)' : healthPct >= 60 ? 'var(--warn)' : 'var(--danger)'"
            />
            <div class="health-detail">
              <div class="health-line">
                <span>通过项</span>
                <strong>{{ metrics.okChecks }} / {{ metrics.totalChecks }}</strong>
              </div>
              <div class="health-line">
                <span>磁盘占用</span>
                <strong>{{ diskPct }}%</strong>
              </div>
              <UiProgress
                :percentage="diskPct"
                :show-text="false"
                :stroke-width="6"
                :color="diskPct > 85 ? 'var(--danger)' : 'var(--accent)'"
              />
              <div class="subtle">
                {{ metrics.disk ? formatBytes(metrics.disk.used) + ' / ' + formatBytes(metrics.disk.total) : '磁盘数据加载中' }}
              </div>
            </div>
          </div>
        </UiCard>

        <!-- 运行环境 -->
        <UiCard>
          <template #header><span class="card-title">运行环境</span></template>
          <UiDescriptions :items="envItems" :column="1" border size="small" />
        </UiCard>
      </div>
      </LayoutBlock>

      <LayoutBlock
        id="traffic"
        label="协议连接 · 实时事件"
        :editing="layoutEdit"
        :hidden="layout.hidden.includes('traffic')"
        :narrow="narrow"
        @move-up="moveBlock('traffic', -1)"
        @move-down="moveBlock('traffic', 1)"
        :style="blockStyle('traffic')"
        @drag-start="startBlockDrag($event, 'traffic')"
        @toggle="toggleBlock('traffic')"
      >
      <div class="two-col">
        <!-- 协议 -->
        <UiCard>
          <template #header>
            <div class="card-head-row">
              <span class="card-title">协议连接</span>
              <div class="spacer" />
              <UiTag type="ok">{{ enabledProtocols }} / {{ protocols.length }} 启用</UiTag>
            </div>
          </template>
          <div class="proto-list">
            <div v-for="[name, p] in protocols" :key="name" class="proto-row">
              <div class="proto-main">
                <div class="proto-name">
                  {{ p.label }}
                  <UiTag :type="p.enabled ? 'ok' : 'info'">{{ p.enabled ? '启用' : '停用' }}</UiTag>
                </div>
                <div class="proto-hint">{{ protocolHint(p) }}</div>
              </div>
              <span class="dot" :class="p.enabled ? 'on' : 'off'" />
            </div>
          </div>
        </UiCard>

        <!-- 事件流 -->
        <UiCard>
          <template #header>
            <div class="card-head-row">
              <span class="card-title">实时事件</span>
              <div class="spacer" />
              <span class="chart-meta">最近 {{ events.length }} 条</span>
            </div>
          </template>
          <div v-if="events.length" class="event-list">
            <div v-for="(e, i) in events" :key="i" class="event-row">
              <UiTag :type="eventTag(e).type">{{ eventTag(e).text }}</UiTag>
              <span class="event-title">{{ eventTitle(e) }}</span>
              <span class="event-time">{{ e.time ? formatRelative(Number(e.time)) : '' }}</span>
            </div>
          </div>
          <UiEmpty v-else description="暂无事件" />
        </UiCard>
      </div>
      </LayoutBlock>
    </template>

    <UiEmpty v-else description="没有取到概览数据">
      <UiButton type="primary" @click="load">重试</UiButton>
    </UiEmpty>

    <!-- 插件注入块：插件实现 adminWidgets() 就能往这一页补内容，不用新开页面 -->
    <LayoutBlock
      id="widgets"
      label="插件注入块"
      :editing="layoutEdit"
      :hidden="layout.hidden.includes('widgets')"
      :narrow="narrow"
      @move-up="moveBlock('widgets', -1)"
      @move-down="moveBlock('widgets', 1)"
      :style="blockStyle('widgets')"
      @drag-start="startBlockDrag($event, 'widgets')"
      @toggle="toggleBlock('widgets')"
    >
      <ExtWidgets slot="overview" />
    </LayoutBlock>
  </div>
  </div>
</template>

<style scoped>
.page-wrap { display: flex; flex-direction: column; gap: 8px; }
.layout-bar { display: flex; justify-content: flex-end; }
.dash { display: flex; flex-direction: column; gap: 14px; }

/* ---------- 状态带 ---------- */
.banner {
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
  padding: 16px 18px;
  border-radius: var(--radius-lg);
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
}
.banner-left { min-width: 220px; }
.banner-title { display: flex; align-items: center; gap: 9px; }
.banner-h { font-size: 15px; font-weight: 640; letter-spacing: -0.01em; }
.banner-sub { margin-top: 4px; font-size: 11.5px; color: var(--text-faint); }
.live {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  height: 20px;
  padding: 0 8px;
  border-radius: var(--radius-full);
  background: var(--ok-soft);
  color: var(--ok);
  font-size: 10.5px;
  font-weight: 600;
}
.live .dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; display: inline-block; }
.live .dot.on { animation: pulse 2s ease-in-out infinite; }
@keyframes pulse { 50% { opacity: 0.35; } }

.banner-stats { display: flex; align-items: center; gap: 26px; }
.bstat-v {
  font-size: 21px;
  font-weight: 660;
  line-height: 1.15;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.02em;
}
.bstat-v em { font-size: 10.5px; font-style: normal; font-weight: 500; color: var(--text-faint); margin-left: 2px; }
.bstat-k { margin-top: 1px; font-size: 10.5px; color: var(--text-faint); }
.banner-actions { margin-left: auto; display: flex; gap: 8px; }

/* ---------- KPI ---------- */
.kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.kpi-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 15px 16px;
  border-radius: var(--radius-lg);
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
}
.kpi-icon {
  width: 34px;
  height: 34px;
  flex: none;
  border-radius: var(--radius);
  display: grid;
  place-items: center;
  font-size: 15px;
}
.tone-accent { background: var(--accent-soft); color: var(--accent-ink); }
.tone-ok { background: var(--ok-soft); color: var(--ok); }
.tone-info { background: var(--info-soft); color: var(--info); }
.tone-warn { background: var(--warn-soft); color: var(--warn); }
.kpi-label { font-size: 11px; color: var(--text-faint); }
.kpi-value {
  font-size: 19px;
  font-weight: 660;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
  line-height: 1.3;
}

/* ---------- 卡片内部 ---------- */
.card-head-row { display: flex; align-items: center; gap: 8px; }
.card-title { font-size: 13px; font-weight: 600; }
.spacer { flex: 1; }

.chart-readout { display: flex; align-items: baseline; gap: 8px; margin-bottom: 6px; }
.chart-value { font-size: 25px; font-weight: 680; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
.chart-unit { font-size: 11.5px; color: var(--text-faint); }
.chart-meta { font-size: 11px; color: var(--text-faint); }

.two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }

.health { display: flex; gap: 18px; align-items: center; }
.health-detail { flex: 1; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.health-line { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-dim); }
.health-line strong { color: var(--text); font-weight: 600; font-variant-numeric: tabular-nums; }
.subtle { font-size: 11px; color: var(--text-faint); }

.proto-list { display: flex; flex-direction: column; }
.proto-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid var(--border-soft);
}
.proto-row:last-child { border-bottom: none; }
.proto-name { display: flex; align-items: center; gap: 7px; font-size: 12.8px; font-weight: 550; }
.proto-hint { font-size: 11px; color: var(--text-faint); margin-top: 2px; }

.dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text-faint); display: inline-block; }
.dot.on { background: var(--ok); }
.dot.off { background: var(--border-strong); }

.event-list { display: flex; flex-direction: column; }
.event-row {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 0;
  border-bottom: 1px solid var(--border-soft);
  font-size: 12.3px;
}
.event-row:last-child { border-bottom: none; }
.event-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-dim); }
.event-time { font-size: 10.5px; color: var(--text-faint); font-variant-numeric: tabular-nums; }

@media (max-width: 1100px) {
  .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .two-col { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 620px) {
  .kpi-grid { grid-template-columns: minmax(0, 1fr); }
  .banner-actions { margin-left: 0; }
}
</style>