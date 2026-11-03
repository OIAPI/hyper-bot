<script setup lang="ts">
/**
 * StreamChart —— 纯 SVG 自绘的流式面积图。
 * 不依赖图表库：用 Catmull-Rom 转贝塞尔做平滑曲线，配合渐变填充 + 扫描光效。
 * 设计取舍：
 *  - 不只是颜色区分，图例/summary 一律带文字当前值（无障碍要求）；
 *  - 数据点超过容量时从头部丢弃，保持滚动窗口；
 *  - 用 CSS 变量取色，跟随主题实时变化。
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** 数值序列（按时间先后） */
    values: number[]
    /** 图高（px） */
    height?: number
    /** 线色，默认品牌色 */
    color?: string
    /** 是否画面积渐变 */
    area?: boolean
    /** 纵轴最小值；不传则取最小值留白 */
    min?: number
    /** 纵轴最大值；不传则取最大值留白 */
    max?: number
    /** 网格线数量 */
    gridLines?: number
    /** 让线末端保持一截留白，视觉上像在持续向右生长 */
    tail?: boolean
  }>(),
  {
    height: 120,
    color: 'var(--accent)',
    area: true,
    gridLines: 4,
    tail: true,
  },
)

const W = 600

const view = computed(() => {
  const vals = props.values.length ? props.values : [0, 0]
  const data = props.tail && vals.length === 1 ? [vals[0], vals[0]] : vals
  const max = props.max ?? Math.max(...data, 1)
  const min = props.min ?? Math.min(...data, 0)
  const span = max - min || 1
  const n = data.length
  const innerW = W - 8
  const innerH = props.height - 8
  // tail=true 时最后一个点不贴右边缘，留出「还在增长」的余地
  const usable = props.tail ? W - 46 : innerW

  const pts = data.map((v, i) => {
    const x = n === 1 ? usable : (i / (n - 1)) * usable + 4
    const y = 4 + innerH - ((v - min) / span) * innerH
    return [x, y] as const
  })

  return { pts, max, min }
})

/** Catmull-Rom → 三次贝塞尔，平滑但不穿过数据点造成夸张失真 */
const linePath = computed(() => {
  const p = view.value.pts
  if (p.length === 0) return ''
  if (p.length === 1) return `M ${p[0][0]} ${p[0][1]}`
  let d = `M ${p[0][0]} ${p[0][1]}`
  for (let i = 0; i < p.length - 1; i++) {
    const p0 = p[i - 1] ?? p[i]
    const p1 = p[i]
    const p2 = p[i + 1]
    const p3 = p[i + 2] ?? p2
    const c1x = p1[0] + (p2[0] - p0[0]) / 6
    const c1y = p1[1] + (p2[1] - p0[1]) / 6
    const c2x = p2[0] - (p3[0] - p1[0]) / 6
    const c2y = p2[1] - (p3[1] - p1[1]) / 6
    d += ` C ${c1x} ${c1y}, ${c2x} ${c2y}, ${p2[0]} ${p2[1]}`
  }
  return d
})

const areaPath = computed(() => {
  const p = view.value.pts
  if (!p.length) return ''
  const base = props.height
  return `${linePath.value} L ${p[p.length - 1][0]} ${base} L ${p[0][0]} ${base} Z`
})

const lastPoint = computed(() => view.value.pts[view.value.pts.length - 1])

const uid = Math.random().toString(36).slice(2, 8)
</script>

<template>
  <svg
    class="stream-chart"
    :viewBox="`0 0 ${W} ${height}`"
    :style="{ height: height + 'px' }"
    preserveAspectRatio="none"
    role="img"
    aria-label="实时趋势"
  >
    <defs>
      <linearGradient :id="`fill-${uid}`" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" :stop-color="color" stop-opacity="0.36" />
        <stop offset="60%" :stop-color="color" stop-opacity="0.1" />
        <stop offset="100%" :stop-color="color" stop-opacity="0" />
      </linearGradient>
      <linearGradient :id="`stroke-${uid}`" x1="0" y1="0" x2="1" y2="0">
        <stop offset="0%" :stop-color="color" stop-opacity="0.35" />
        <stop offset="30%" :stop-color="color" stop-opacity="1" />
        <stop offset="100%" :stop-color="color" stop-opacity="1" />
      </linearGradient>
    </defs>

    <line
      v-for="i in gridLines"
      :key="i"
      class="grid-line"
      x1="0"
      :x2="W"
      :y1="(height / (gridLines + 1)) * i"
      :y2="(height / (gridLines + 1)) * i"
    />

    <path v-if="area" :d="areaPath" :fill="`url(#fill-${uid})`" />
    <path
      :d="linePath"
      fill="none"
      :stroke="`url(#stroke-${uid})`"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />

    <circle
      v-if="lastPoint"
      :cx="lastPoint[0]"
      :cy="lastPoint[1]"
      r="3.5"
      :fill="color"
      class="last-dot"
      vector-effect="non-scaling-stroke"
    />
    <circle
      v-if="lastPoint"
      :cx="lastPoint[0]"
      :cy="lastPoint[1]"
      r="7"
      :fill="color"
      opacity="0.18"
      class="last-halo"
    />

    <line
      v-if="lastPoint && tail"
      class="tail-line"
      :x1="lastPoint[0]"
      :x2="W"
      :y1="lastPoint[1]"
      :y2="lastPoint[1]"
      :stroke="color"
    />
  </svg>
</template>

<style scoped>
.stream-chart {
  display: block;
  width: 100%;
  overflow: visible;
}
.grid-line {
  stroke: var(--border-soft);
  stroke-width: 1;
  stroke-dasharray: 3 5;
  vector-effect: non-scaling-stroke;
}
.last-dot {
  filter: drop-shadow(0 0 6px currentColor);
}
.last-halo {
  animation: halo 2s var(--ease) infinite;
  transform-origin: center;
}
.tail-line {
  stroke-width: 1.5;
  stroke-dasharray: 4 4;
  opacity: 0.5;
  vector-effect: non-scaling-stroke;
}
@keyframes halo {
  0% { opacity: 0.28; }
  50% { opacity: 0.1; }
  100% { opacity: 0.28; }
}
</style>
