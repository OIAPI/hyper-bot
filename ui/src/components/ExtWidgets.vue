<script setup lang="ts">
/**
 * 插件注入块：直接长在已有页面里（概览、账号页…）。
 *
 * 与 /ext/:plugin 整页的区别是「不占侧边栏入口」——
 * 插件想补一小块（最近投递、最新 RSS）就不必再开一个页面。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { extApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import type { ExtBlock, ExtWidget } from '@/types/api'
import type { UiColumn } from '@/ui/types'
import { ensurePluginHost, pluginWidgets } from '@/ext/host'

const props = withDefaults(defineProps<{ slot?: string }>(), { slot: 'overview' })

const toast = useToastStore()
const items = ref<ExtWidget[]>([])
const loading = ref(false)
const tables = ref<Record<string, Record<string, unknown>[]>>({})

function columnsOf(block: ExtBlock): UiColumn[] {
  return (block.columns ?? []).map((c) => ({
    key: c.key,
    title: c.title,
    width: c.width,
    minWidth: c.minWidth,
  }))
}

async function loadTable(widget: ExtWidget, index: number, block: ExtBlock): Promise<void> {
  const key = widget.plugin + '#' + index
  if (!block.endpoint) return
  tables.value = { ...tables.value, [key]: [] }
  try {
    const data = await extApi.call(widget.plugin, block.endpoint, 'GET', block.params ?? {})
    const rows = (data as { items?: Record<string, unknown>[] })?.items ?? []
    tables.value = { ...tables.value, [key]: rows }
  } catch {
    tables.value = { ...tables.value, [key]: [] }
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await extApi.widgets(props.slot)
    items.value = res.items ?? []
    for (const [i, widget] of items.value.entries()) {
      for (const block of widget.blocks) {
        if (block.type === 'table' && block.endpoint) {
          void loadTable(widget, i, block)
        }
      }
    }
  } catch (e) {
    // 注入块属于「锦上添花」，失败不打扰用户
    toast.fromError(e, '插件注入块加载失败')
  } finally {
    loading.value = false
  }
}

/** 插件自带组件注册到当前 slot 的块（v3.2 组件注入） */
const injected = computed(() => pluginWidgets[props.slot] ?? [])

onMounted(() => {
  void load()
  // 插件脚本可能晚于页面挂载：ensurePluginHost 内部去重，注册完会自动渲染
  void ensurePluginHost()
})
watch(() => props.slot, load)
</script>

<template>
  <div v-if="items.length || injected.length" class="ext-widgets">
    <!-- 插件自带组件：在插件 JS 里 registerWidget('overview', {...}) 注册 -->
    <UiCard v-for="entry in injected" :key="entry.key">
      <template #header>
        <div class="widget-head">
          <span class="card-title">{{ entry.title }}</span>
          <span class="widget-from">{{ entry.pluginName }}</span>
        </div>
      </template>
      <component :is="entry.component" />
    </UiCard>

    <UiCard v-for="(widget, i) in items" :key="widget.plugin + i">
      <template #header>
        <div class="widget-head">
          <span class="card-title">{{ widget.title }}</span>
          <span class="widget-from">{{ widget.plugin.split('\\').pop() }}</span>
        </div>
      </template>

      <template v-for="(block, j) in widget.blocks" :key="j">
        <div v-if="block.type === 'text'" class="widget-text">{{ block.text }}</div>
        <UiTable
          v-else-if="block.type === 'table'"
          :data="tables[widget.plugin + '#' + i] ?? []"
          :columns="columnsOf(block)"
          size="small"
          :row-key="block.rowKey"
        />
      </template>
    </UiCard>
  </div>
</template>

<style scoped>
.ext-widgets { display: flex; flex-direction: column; gap: 14px; }
.widget-head { display: flex; align-items: center; gap: 8px; }
.widget-from { font-size: 10.5px; color: var(--text-faint); font-family: var(--font-mono); }
.widget-text { font-size: 12.5px; color: var(--text-dim); line-height: 1.8; }
</style>
