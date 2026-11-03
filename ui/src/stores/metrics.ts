import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { overviewApi, systemApi } from '@/api'
import type { OverviewCounts, HealthData, DiskData } from '@/types/api'

/** 采样窗口：300 个点 × 3s ≈ 15 分钟历史 */
export const MAX_SAMPLES = 300
/** 会话桶容量（事件页用） */
const BUCKET_CAP = 60

export interface Sample {
  t: number
  /** 服务端累计事件数 */
  events: number
  /** 服务端累计消息数 */
  messages: number
  /** 每分钟新增事件数（由累计值差分得到） */
  eventRate: number
  /** 每分钟新增消息数 */
  messageRate: number
  /** 一次采样的往返耗时 ms */
  latency: number
  /** 健康检查通过率 0-100 */
  health: number
  /** 磁盘占用率 0-100 */
  disk: number
  /** 本次采样是否成功 */
  ok: boolean
}

export interface RateBucket {
  t: number
  created: number
  updated: number
  deleted: number
  open: number
}

/**
 * 指标采样仓库。
 * 为什么单独抽出来：概览页、事件页都要「实时」数据，各自轮询会把接口调用翻倍。
 * 这里以 3s 间隔只拉一份轻量数据，所有订阅方共享同一个定时器。
 */
export const useMetricsStore = defineStore('metrics', () => {
  const samples = ref<Sample[]>([])
  const buckets = ref<RateBucket[]>([])
  const lastCounts = ref<OverviewCounts | null>(null)
  const health = ref<HealthData | null>(null)
  const disk = ref<DiskData | null>(null)
  const live = ref(true)
  const error = ref('')
  const startedAt = ref(Date.now())

  let timer = 0
  let subscribers = 0
  let inflight = false
  let lastHealthAt = 0

  async function sample(): Promise<void> {
    if (inflight) return
    inflight = true
    const t0 = performance.now()
    try {
      const counts = await overviewApi.counts()
      const latency = Math.round(performance.now() - t0)
      const now = Date.now()
      const prev = lastCounts.value
      const prevSample = samples.value[samples.value.length - 1]
      const dtSec = prevSample ? Math.max(1, (now - prevSample.t) / 1000) : 0

      const dEvents = prev ? Math.max(0, counts.events - prev.events) : 0
      const dMessages = prev ? Math.max(0, counts.messages - prev.messages) : 0
      const eventRate = dtSec ? Math.round((dEvents / dtSec) * 60) : 0
      const messageRate = dtSec ? Math.round((dMessages / dtSec) * 60) : 0

      // 健康检查与磁盘统计偏重，15s 才拉一次
      if (now - lastHealthAt > 15_000 || !health.value) {
        lastHealthAt = now
        health.value = await systemApi.health().catch(() => health.value)
        disk.value = await systemApi.disk().catch(() => disk.value)
      }

      const checks = health.value?.checks ?? []
      const healthRate = checks.length
        ? (checks.filter((c) => c.ok).length / checks.length) * 100
        : 100
      const diskRate = disk.value ? (disk.value.used / (disk.value.total || 1)) * 100 : 0

      samples.value = [
        ...samples.value,
        {
          t: now,
          events: counts.events,
          messages: counts.messages,
          eventRate,
          messageRate,
          latency,
          health: Math.round(healthRate),
          disk: Math.round(diskRate * 10) / 10,
          ok: true,
        },
      ].slice(-MAX_SAMPLES)

      lastCounts.value = counts
      error.value = ''

      if (prev) {
        buckets.value = [
          ...buckets.value,
          { t: now, created: dEvents, updated: dMessages, deleted: 0, open: dEvents + dMessages },
        ].slice(-BUCKET_CAP)
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : '采样失败'
      const prevSample = samples.value[samples.value.length - 1]
      if (prevSample) {
        // 失败也留一个点，让曲线能把断档画出来，而不是假装一切正常
        samples.value = [
          ...samples.value,
          { ...prevSample, t: Date.now(), eventRate: 0, messageRate: 0, ok: false },
        ].slice(-MAX_SAMPLES)
      }
    } finally {
      inflight = false
    }
  }

  function start(): void {
    subscribers++
    if (timer) return
    void sample()
    timer = window.setInterval(() => {
      if (live.value) void sample()
    }, 3000)
  }

  function stop(): void {
    subscribers = Math.max(0, subscribers - 1)
    if (subscribers === 0 && timer) {
      window.clearInterval(timer)
      timer = 0
    }
  }

  function setLive(v: boolean): void {
    live.value = v
    if (v) void sample()
  }

  /** 订阅：返回释放函数，最后一个订阅者离开时自动停表 */
  function acquire(): () => void {
    start()
    let released = false
    return () => {
      if (released) return
      released = true
      stop()
    }
  }

  const eventRateSeries = computed(() => samples.value.map((s) => s.eventRate))
  const messageRateSeries = computed(() => samples.value.map((s) => s.messageRate))
  const eventsSeries = computed(() => samples.value.map((s) => s.events))
  const latencySeries = computed(() => samples.value.map((s) => s.latency))
  const healthSeries = computed(() => samples.value.map((s) => s.health))
  const diskSeries = computed(() => samples.value.map((s) => s.disk))

  const currentRate = computed(() => eventRateSeries.value[eventRateSeries.value.length - 1] ?? 0)
  const currentMessageRate = computed(
    () => messageRateSeries.value[messageRateSeries.value.length - 1] ?? 0,
  )
  const currentLatency = computed(() => latencySeries.value[latencySeries.value.length - 1] ?? 0)
  const okChecks = computed(() => health.value?.checks.filter((c) => c.ok).length ?? 0)
  const totalChecks = computed(() => health.value?.checks.length ?? 0)
  const windowEvents = computed(() => buckets.value.reduce((a, b) => a + b.open, 0))
  const sessionSeconds = computed(() => Math.round((Date.now() - startedAt.value) / 1000))

  return {
    samples,
    buckets,
    lastCounts,
    health,
    disk,
    live,
    error,
    startedAt,
    eventRateSeries,
    messageRateSeries,
    eventsSeries,
    latencySeries,
    healthSeries,
    diskSeries,
    currentRate,
    currentMessageRate,
    currentLatency,
    okChecks,
    totalChecks,
    windowEvents,
    sessionSeconds,
    acquire,
    setLive,
    sample,
  }
})
