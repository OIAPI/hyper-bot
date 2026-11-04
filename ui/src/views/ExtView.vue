<script setup lang="ts">
/**
 * 插件后台页面的通用渲染器。
 *
 * 插件只提供 schema（文本 / 表格 / 表单 / 操作按钮）和接口，
 * 这里负责画出来、取数据、提交。加一个后台功能插件不需要改前端、不需要重新构建。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { extApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import type { ExtBlock, ExtPageData } from '@/types/api'
import type { UiColumn } from '@/ui/types'

const route = useRoute()
const toast = useToastStore()

const plugin = computed(() => String(route.params.plugin ?? ''))
const page = ref<ExtPageData | null>(null)
const loading = ref(false)
const error = ref('')

const tables = ref<Record<number, { rows: Record<string, unknown>[]; loading: boolean; error: string }>>({})
const forms = ref<Record<number, Record<string, string>>>({})
const busy = ref('')

const blocks = computed<ExtBlock[]>(() => page.value?.blocks ?? [])

function columnsOf(block: ExtBlock): UiColumn[] {
  return (block.columns ?? []).map((c) => ({
    key: c.key,
    title: c.title,
    width: c.width,
    minWidth: c.minWidth,
  }))
}

async function loadPage(): Promise<void> {
  loading.value = true
  error.value = ''
  page.value = null
  tables.value = {}
  forms.value = {}
  try {
    page.value = await extApi.page(plugin.value)
    await Promise.all(blocks.value.map((b, i) => (b.type === 'table' && b.endpoint ? fetchTable(b, i) : Promise.resolve())))
  } catch (e) {
    error.value = e instanceof Error ? e.message : '加载插件页面失败'
  } finally {
    loading.value = false
  }
}

async function fetchTable(block: ExtBlock, index: number): Promise<void> {
  tables.value = { ...tables.value, [index]: { rows: [], loading: true, error: '' } }
  try {
    const data = (await extApi.call(plugin.value, block.endpoint ?? '', 'GET')) as { items?: Record<string, unknown>[] }
    tables.value = {
      ...tables.value,
      [index]: { rows: data?.items ?? [], loading: false, error: '' },
    }
  } catch (e) {
    tables.value = {
      ...tables.value,
      [index]: { rows: [], loading: false, error: e instanceof Error ? e.message : '加载失败' },
    }
  }
}

function setField(index: number, key: string, value: string): void {
  const current = forms.value[index] ?? {}
  forms.value = { ...forms.value, [index]: { ...current, [key]: value } }
}

async function submitForm(block: ExtBlock, index: number): Promise<void> {
  if (!block.endpoint) return
  busy.value = 'form-' + index
  try {
    const params = forms.value[index] ?? {}
    await extApi.call(plugin.value, block.endpoint, block.method ?? 'POST', params)
    toast.ok('已提交')
    forms.value = { ...forms.value, [index]: {} }
    await Promise.all(blocks.value.map((b, i) => (b.type === 'table' && b.endpoint ? fetchTable(b, i) : Promise.resolve())))
  } catch (e) {
    toast.fromError(e, '提交失败')
  } finally {
    busy.value = ''
  }
}

async function runAction(endpoint: string, method: string, base: Record<string, string>, prompt?: string, confirmText?: string): Promise<void> {
  const params: Record<string, string> = { ...base }
  if (prompt) {
    const value = window.prompt(prompt, Object.values(params)[0] ?? '')
    if (value === null) return
    params[Object.keys(params)[0] ?? 'id'] = value
  }
  if (confirmText && !window.confirm(confirmText)) return
  busy.value = 'action-' + endpoint + method
  try {
    await extApi.call(plugin.value, endpoint, method, params)
    toast.ok('已执行')
    await Promise.all(blocks.value.map((b, i) => (b.type === 'table' && b.endpoint ? fetchTable(b, i) : Promise.resolve())))
  } catch (e) {
    toast.fromError(e, '执行失败')
  } finally {
    busy.value = ''
  }
}

watch(plugin, () => void loadPage())
onMounted(loadPage)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>{{ page?.title || '插件页面' }}</h1>
        <p class="sub">由插件 {{ page?.name || plugin }} 提供 · 页面结构与接口都来自后端，升级插件无需重新构建前端</p>
      </div>
      <UiButton size="small" :loading="loading" @click="loadPage">刷新</UiButton>
    </header>

    <UiAlert v-if="error" type="danger" :closable="false">{{ error }}</UiAlert>

    <UiSkeleton v-if="loading && !page" :rows="6" />

    <template v-else-if="page">
      <template v-for="(block, i) in blocks" :key="i">
        <UiAlert v-if="block.type === 'text'" type="info" :closable="false">{{ block.text }}</UiAlert>

        <UiCard v-else-if="block.type === 'table'" :padding="'none'">
          <template #header><span class="card-title">{{ block.title || '列表' }}</span></template>
          <div v-if="tables[i]?.error" class="form-error">{{ tables[i].error }}</div>
          <UiTable
            :data="tables[i]?.rows ?? []"
            :columns="columnsOf(block)"
            :row-key="block.rowKey || 'id'"
            :loading="tables[i]?.loading ?? false"
            empty-text="暂无数据"
          />
        </UiCard>

        <UiCard v-else-if="block.type === 'form'">
          <template #header><span class="card-title">{{ block.title || '表单' }}</span></template>
          <div class="form-grid">
            <label v-for="f in block.fields ?? []" :key="f.key" class="field" :class="{ 'span-2': (f.placeholder ?? '').length > 40 }">
              <span class="field-label">{{ f.label }}</span>
              <UiSelect
                v-if="f.options && f.options.length"
                :model-value="forms[i]?.[f.key] ?? ''"
                :options="f.options"
                @update:model-value="(v: any) => setField(i, f.key, String(v))"
              />
              <UiInput
                v-else
                :model-value="forms[i]?.[f.key] ?? ''"
                :type="f.type === 'password' ? 'password' : 'text'"
                :placeholder="f.placeholder || ''"
                @update:model-value="(v: string | number) => setField(i, f.key, String(v))"
              />
            </label>
          </div>
          <div class="row">
            <UiButton size="small" type="primary" :loading="busy === 'form-' + i" @click="submitForm(block, i)">
              {{ block.submitLabel || '提交' }}
            </UiButton>
          </div>
        </UiCard>

        <UiCard v-else-if="block.type === 'actions'">
          <template #header><span class="card-title">{{ block.title || '操作' }}</span></template>
          <div class="row wrap">
            <UiButton
              v-for="(a, k) in block.actions ?? []"
              :key="k"
              size="small"
              :loading="busy === 'action-' + a.endpoint + (a.method || 'POST')"
              @click="runAction(a.endpoint, a.method || 'POST', a.params ?? {}, a.prompt, a.confirm)"
            >
              {{ a.label }}
            </UiButton>
          </div>
        </UiCard>
      </template>
    </template>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.page-head h1 { margin: 0; font-size: 19px; }
.sub { margin: 4px 0 0; font-size: 12px; color: var(--text-faint); line-height: 1.7; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field.span-2 { grid-column: span 2; }
.field-label { font-size: 11.5px; color: var(--text-dim); }
.row { display: flex; gap: 8px; }
.row.wrap { flex-wrap: wrap; }
.form-error {
  margin: 8px 12px; padding: 7px 10px; border-radius: var(--radius-sm);
  border: 1px solid var(--danger, #d33); color: var(--danger, #d33); font-size: 12px;
}
</style>
