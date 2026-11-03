<script setup lang="ts">
/**
 * 布局自检：排查「页面只有几像素高 / 控件挤在一起」这类问题。
 *
 * 不依赖组件库（用原生元素），即使适配器挂了也能看，方便用户截图回传。
 */
import { onMounted, ref } from 'vue'

interface Row { name: string; display: string; height: string; width: string; overflow: string }

const rows = ref<Row[]>([])
const info = ref('')
const text = ref('')

function collect(): string {
  const out: string[] = []
  out.push('viewport ' + window.innerWidth + 'x' + window.innerHeight + ' dpr=' + window.devicePixelRatio)
  out.push('doc scroll ' + document.documentElement.scrollWidth + 'x' + document.documentElement.scrollHeight)
  const list: Row[] = []
  const targets: Array<[string, Element | null]> = [
    ['html', document.documentElement],
    ['body', document.body],
    ['#app', document.getElementById('app')],
    ['.shell', document.querySelector('.shell')],
    ['.main', document.querySelector('.main')],
    ['.content', document.querySelector('.content')],
    ['.page', document.querySelector('.page')],
  ]
  for (const [name, el] of targets) {
    if (!el) continue
    const r = el.getBoundingClientRect()
    const cs = getComputedStyle(el)
    list.push({
      name,
      display: cs.display,
      height: Math.round(r.height) + 'px (' + cs.height + ')',
      width: Math.round(r.width) + 'px',
      overflow: cs.overflow + '/' + cs.overflowY,
    })
  }
  rows.value = list
  const cards = Array.from(document.querySelectorAll('.el-card')).slice(0, 6)
  cards.forEach((c, i) => {
    const r = c.getBoundingClientRect()
    out.push('card[' + i + '] y=' + Math.round(r.y) + ' h=' + Math.round(r.height) + ' w=' + Math.round(r.width))
  })
  out.push('cards=' + document.querySelectorAll('.el-card').length)
  // 命中测试：内容区里从上到下几个点，分别落在哪个元素上（用来认出那条「白条」是什么）
  const cx = Math.round(window.innerWidth / 2)
  for (const y of [40, 80, 120, 200, 320, 480]) {
    const el = document.elementFromPoint(cx, y)
    if (!el) { out.push('hit y=' + y + ' -> null'); continue }
    const r = el.getBoundingClientRect()
    out.push('hit y=' + y + ' -> ' + el.tagName.toLowerCase() + '.' + (el.className || '') +
      ' [' + Math.round(r.width) + 'x' + Math.round(r.height) + '] "' + (el.textContent || '').trim().slice(0, 24) + '"')
  }
  list.forEach((r) => out.push(r.name + ' h=' + r.height + ' display=' + r.display + ' overflow=' + r.overflow))
  return out.join('\n')
}

function refresh(): void {
  info.value = navigator.userAgent
  text.value = collect()
}

onMounted(refresh)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>布局自检</h1>
        <p class="sub">排查「页面只有几像素高 / 卡片挤在一起」时，把下面这段发给开发者。</p>
      </div>
      <button class="diag-btn" type="button" @click="refresh">重新测量</button>
    </header>

    <div class="diag-box">
      <div class="diag-line">{{ info }}</div>
      <table class="diag-table">
        <thead>
          <tr><th>节点</th><th>display</th><th>height</th><th>width</th><th>overflow</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.name">
            <td>{{ r.name }}</td><td>{{ r.display }}</td><td>{{ r.height }}</td><td>{{ r.width }}</td><td>{{ r.overflow }}</td>
          </tr>
        </tbody>
      </table>
      <pre class="diag-pre">{{ text }}</pre>
    </div>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 12px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; }
.page-head h1 { margin: 0; font-size: 17px; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12px; }
.diag-btn { padding: 4px 10px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text-dim); cursor: pointer; font-size: 12px; }
.diag-box { border: 1px solid var(--border-soft); border-radius: 8px; padding: 12px; background: var(--bg-card); }
.diag-line { font-size: 11.5px; color: var(--text-faint); word-break: break-all; }
.diag-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11.5px; }
.diag-table th, .diag-table td { text-align: left; padding: 4px 6px; border-bottom: 1px solid var(--border-soft); }
.diag-table th { color: var(--text-faint); font-weight: 500; }
.diag-pre { margin: 0; padding: 10px; background: var(--bg); border-radius: 6px; font-size: 11px; line-height: 1.7; white-space: pre-wrap; user-select: text; }
</style>