<script setup lang="ts">
/**
 * 配置：树形编辑 + 告警提示 + 原文视图。
 *
 * 三件事是旧版没有的：
 *  1. 递归渲染，三层嵌套（protocol.onebot.forward.enable）也能改；
 *  2. 告警逐条展示（分号截断、# 被当注释、回读不一致）；
 *  3. 保存后显示备份文件路径，用户知道怎么回滚。
 *
 * 降级说明：UiTable 契约没有树形表格能力（原先的 tree-props / expand-row-keys），
 * 这里改成「自绘缩进 + 展开/收起」的扁平表格，缩进深度与默认展开层级（前两层）
 * 与原树形表格保持一致，保证换组件库后行为不变。
 */
import { computed, onMounted, ref } from 'vue'
import { ArrowDown, ArrowRight } from '@element-plus/icons-vue'
import { settingsApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { displayValue, isConfigNode } from '@/utils/format'
import type { UiColumn } from '@/ui/types'
import type { SettingsData } from '@/types/api'

const toast = useToastStore()

const data = ref<SettingsData | null>(null)
const loading = ref(true)
const mode = ref<'tree' | 'raw'>('tree')
const rawText = ref('')
const search = ref('')

const editing = ref<{ path: string; original: unknown } | null>(null)
const editOpen = ref(false)
const editValue = ref('')
const saving = ref(false)

/** 展开状态：键 = 完整配置路径，缺省时按深度决定（前两层展开） */
const expanded = ref<Record<string, boolean>>({})

const modeOptions = [
  { label: '配置项', value: 'tree' },
  { label: '原文', value: 'raw' },
]

const columns: UiColumn[] = [
  { key: 'nodeKey', title: '配置项', minWidth: 240 },
  { key: 'display', title: '值', minWidth: 220 },
  { key: 'type', title: '类型', width: 90 },
  { key: 'actions', title: '操作', width: 86, align: 'right' },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await settingsApi.get()
    if (mode.value === 'raw') await loadRaw()
  } catch (e) {
    toast.fromError(e, '加载配置失败')
  } finally {
    loading.value = false
  }
}

async function loadRaw(): Promise<void> {
  try {
    const res = await settingsApi.raw()
    rawText.value = res.text
  } catch (e) {
    toast.fromError(e, '读取配置文件失败')
  }
}

onMounted(load)

/** 关键字过滤：只保留命中路径的子树 */
const tree = computed<Record<string, unknown>>(() => {
  const cfg = data.value?.config ?? {}
  const kw = search.value.trim().toLowerCase()
  if (!kw) return cfg

  function filterNode(input: unknown): unknown {
    if (input === null || typeof input !== 'object') return input
    const out: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(input as Record<string, unknown>)) {
      if (k.toLowerCase().includes(kw)) {
        out[k] = v
        continue
      }
      if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
        const sub = filterNode(v) as Record<string, unknown>
        if (Object.keys(sub).length) out[k] = sub
      }
    }
    return out
  }

  return filterNode(cfg) as Record<string, unknown>
})

/** 表格的一行：分支带 children，叶子可直接改值 */
interface TreeRow {
  /** 行唯一键 = 完整配置路径 */
  path: string
  nodeKey: string
  value: unknown
  type: string
  display: string
  leaf: boolean
  children?: TreeRow[]
}

interface FlatRow extends TreeRow {
  /** 缩进深度，0 为顶层 */
  depth: number
  /** 当前是否展开（叶子恒为 false） */
  open: boolean
}

interface TreeSection {
  key: string
  rows: TreeRow[]
  count: number
  flat: FlatRow[]
}

function typeHint(v: unknown): string {
  if (v === null) return 'null'
  if (Array.isArray(v)) return 'array'
  return typeof v
}

function buildRows(node: Record<string, unknown>, parentPath: string): TreeRow[] {
  return Object.entries(node).map(([key, value]) => {
    const path = parentPath ? parentPath + '.' + key : key
    if (isConfigNode(value)) {
      return {
        path,
        nodeKey: key,
        value,
        type: 'object',
        display: '',
        leaf: false,
        children: buildRows(value, path),
      }
    }
    return { path, nodeKey: key, value, type: typeHint(value), display: displayValue(value), leaf: true }
  })
}

/** 默认展开前两层，与旧版树形表格的 depth < 2 一致 */
function isOpen(row: TreeRow, depth: number): boolean {
  const saved = expanded.value[row.path]
  return saved === undefined ? depth < 2 : saved
}

function flatten(rows: TreeRow[], depth: number, out: FlatRow[]): FlatRow[] {
  for (const row of rows) {
    const open = !row.leaf && isOpen(row, depth)
    out.push({ ...row, depth, open })
    if (open && row.children) flatten(row.children, depth + 1, out)
  }
  return out
}

const sections = computed<TreeSection[]>(() =>
  Object.entries(tree.value).map(([key, value]) => {
    if (isConfigNode(value)) {
      const rows = buildRows(value, key)
      return { key, rows, count: Object.keys(value).length, flat: flatten(rows, 0, []) }
    }
    const rows: TreeRow[] = [
      { path: key, nodeKey: key, value, type: typeHint(value), display: displayValue(value), leaf: true },
    ]
    return { key, rows, count: 1, flat: flatten(rows, 0, []) }
  }),
)

/** 表格插槽只给到普通对象，这里收敛成行类型 */
function asRow(row: unknown): FlatRow {
  return row as FlatRow
}

function indent(row: unknown): string {
  return 'padding-left: ' + asRow(row).depth * 14 + 'px'
}

function rowOpen(row: unknown): boolean {
  return asRow(row).open
}

function toggleRow(row: unknown): void {
  const r = asRow(row)
  expanded.value[r.path] = !r.open
}

function childCount(row: unknown): number {
  return asRow(row).children?.length ?? 0
}

function openEdit(row: TreeRow): void {
  editing.value = { path: row.path, original: row.value }
  editValue.value =
    row.value === null ? '' : typeof row.value === 'object' ? JSON.stringify(row.value) : String(row.value)
  editOpen.value = true
}

function onEditClick(row: unknown): void {
  openEdit(row as TreeRow)
}

function closeEdit(): void {
  editOpen.value = false
  editing.value = null
}

/** 根据原值类型推断要写回的值类型 */
function coerce(raw: string, original: unknown): unknown {
  if (typeof original === 'boolean') {
    const v = raw.trim().toLowerCase()
    return v === 'true' || v === '1' || v === 'yes' || v === 'on'
  }
  if (typeof original === 'number') {
    const n = Number(raw)
    return Number.isFinite(n) ? n : raw
  }
  if (Array.isArray(original) || (original !== null && typeof original === 'object')) {
    try {
      return JSON.parse(raw)
    } catch {
      return raw
    }
  }
  return raw
}

async function save(): Promise<void> {
  if (!editing.value) return
  saving.value = true
  const { path, original } = editing.value
  try {
    const res = await settingsApi.update(path, coerce(editValue.value, original))
    toast.ok('已保存' + (res.backup ? '，旧值已备份' : ''))
    closeEdit()
    await load()
  } catch (e) {
    toast.fromError(e, '保存失败')
  } finally {
    saving.value = false
  }
}

async function reloadConfig(): Promise<void> {
  try {
    await settingsApi.reload()
    toast.ok('配置已重新加载')
    await load()
  } catch (e) {
    toast.fromError(e, '重载失败')
  }
}

function onModeChange(val: string | number | boolean | undefined): void {
  if (val === 'raw') void loadRaw()
}

const warnings = computed(() => data.value?.warnings ?? [])
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>配置</h1>
        <p class="sub">逐项查看与修改配置，保存后旧值会备份，服务重启生效。</p>
      </div>
    </header>

    <!-- 工具栏 -->
    <UiCard>
      <div class="toolbar">
        <UiSegmented v-model="mode" :options="modeOptions" @change="onModeChange" />

        <UiInput
          v-if="mode === 'tree'"
          v-model="search"
          class="search"
          size="small"
          placeholder="过滤配置项（按键名）…"
          clearable
        />

        <div class="spacer" />

        <UiTag v-if="data" :type="data.writable ? 'ok' : 'danger'" size="small">
          {{ data.writable ? '可写' : '只读' }}
        </UiTag>
        <span v-if="data" class="path-text mono truncate">{{ data.path }}</span>
        <UiButton size="small" @click="reloadConfig">重新加载</UiButton>
      </div>
    </UiCard>

    <!-- 配置告警 -->
    <UiAlert
      v-if="warnings.length"
      type="warn"
      :closable="false"
      :title="'配置告警（' + warnings.length + ' 项）'"
    >
      <ul class="warn-list">
        <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
      </ul>
    </UiAlert>

    <UiCard v-if="loading">
      <UiSkeleton :rows="6" animated />
    </UiCard>

    <template v-else-if="mode === 'tree'">
      <UiCard v-for="section in sections" :key="section.key" padding="none">
        <template #header>
          <div class="card-head-row">
            <span class="card-title mono">[{{ section.key }}]</span>
            <div class="spacer" />
            <UiTag type="info" effect="plain" size="small">{{ section.count }} 项</UiTag>
          </div>
        </template>
        <UiTable :data="section.flat" :columns="columns" row-key="path" size="default">
          <template #cell-nodeKey="{ row }">
            <div class="tree-key" :style="indent(row)">
              <button
                v-if="!row.leaf"
                type="button"
                class="caret"
                :aria-label="rowOpen(row) ? '收起' : '展开'"
                @click="toggleRow(row)"
              >
                <span class="caret" :class="{ open: rowOpen(row) }"><component :is="rowOpen(row) ? ArrowDown : ArrowRight" /></span>
              </button>
              <span v-else class="caret-gap" />
              <span class="mono row-key">{{ row.nodeKey }}</span>
            </div>
          </template>
          <template #cell-display="{ row }">
            <span v-if="row.leaf" class="mono row-val" :class="'v-' + row.type">{{ row.display }}</span>
            <UiTag v-else type="info" effect="plain" size="small">{{ childCount(row) }} 项</UiTag>
          </template>
          <template #cell-type="{ row }">
            <span class="tiny faint">{{ row.type }}</span>
          </template>
          <template #cell-actions="{ row }">
            <UiButton
              v-if="row.leaf"
              type="text"
              size="small"
              @click="onEditClick(row)"
            >
              修改
            </UiButton>
          </template>
          <template #empty>
            <UiEmpty description="没有匹配的配置项" />
          </template>
        </UiTable>
      </UiCard>

      <UiEmpty v-if="!sections.length" description="没有匹配的配置项" />
    </template>

    <!-- 原文 -->
    <UiCard v-else padding="none">
      <pre class="raw mono">{{ rawText }}</pre>
    </UiCard>

    <UiDialog v-model="editOpen" :title="'修改 ' + (editing?.path ?? '')" width="520px" @closed="closeEdit">
      <UiForm label-position="top">
        <UiFormItem label="值">
          <UiInput v-model="editValue" class="mono" @enter="save" />
        </UiFormItem>
      </UiForm>
      <div class="field-hint">
        布尔值可填 true / false，数字会自动转换，数组和对象使用 JSON 语法。 修改后需重启服务才会生效。
      </div>
      <template #footer>
        <div class="row right gap-8">
          <UiButton size="small" @click="closeEdit">取消</UiButton>
          <UiButton size="small" type="primary" :loading="saving" @click="save">
            {{ saving ? '保存中…' : '保存' }}
          </UiButton>
        </div>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.page-head { margin-bottom: 0; align-items: flex-end; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }

.toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.search { max-width: 260px; }
.path-text { max-width: 320px; font-size: 11.5px; color: var(--text-faint); }
.spacer { flex: 1; }
.row { display: flex; align-items: center; }
.right { justify-content: flex-end; }
.gap-8 { gap: 8px; }

.warn-list {
  margin: 4px 0 0;
  padding-left: 18px;
  font-size: 12.5px;
  line-height: 1.8;
  color: var(--text-dim);
}

.tree-key { display: flex; align-items: center; gap: 4px; min-width: 0; }
.caret {
  width: 16px;
  height: 16px;
  padding: 0;
  border: none;
  border-radius: 3px;
  background: transparent;
  color: var(--text-faint);
  font-size: 10px;
  line-height: 1;
  cursor: pointer;
  flex: none;
}
.caret:hover { background: var(--bg-hover); color: var(--text); }
.caret-gap { width: 16px; flex: none; }
.row-key { font-size: 12.5px; color: var(--text-dim); word-break: break-all; }
.row-val {
  font-size: 12.5px;
  color: var(--text);
  overflow-wrap: anywhere;
}
.v-boolean { color: var(--accent); }
.v-number { color: var(--ok); }
.v-null { color: var(--text-faint); font-style: italic; }

.raw {
  margin: 0;
  max-height: calc(100vh - 300px);
  padding: 16px;
  overflow: auto;
  font-size: 12px;
  line-height: 1.7;
  color: var(--text-dim);
  white-space: pre-wrap;
  word-break: break-all;
}
.caret { display: inline-flex; align-items: center; justify-content: center; }
.caret svg { width: 12px; height: 12px; display: block; }
</style>