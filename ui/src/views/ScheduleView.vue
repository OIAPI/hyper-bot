<script setup lang="ts">
/**
 * 定时任务：cron 表达式 + 动作（发消息 / 调静态方法）。
 *
 * 执行由后端 Runner 负责（swoole 定时器 / worker 轮询 / HTTP 顺带 tick），
 * 这里只管增删改查与「立即执行一次」。
 */
import { computed, onMounted, ref } from 'vue'
import { scheduleApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { ScheduleData, ScheduleJob } from '@/types/api'
import type { UiColumn } from '@/ui/types'
import { sortRows, type SortState } from '@/utils/sortRows'

const auth = useAuthStore()
const toast = useToastStore()

const data = ref<ScheduleData | null>(null)
const loading = ref(false)
const canWrite = computed(() => auth.can('settings.write'))

const columns: UiColumn[] = [
  { key: 'name', title: '任务', minWidth: 180 },
  { key: 'cron', title: '表达式', width: 150 },
  { key: 'action', title: '动作', minWidth: 200 },
  { key: 'next_run_text', title: '下次执行', width: 170 },
  { key: 'last_run_text', title: '上次执行', width: 170 },
  { key: 'enabled', title: '启用', width: 80 },
  { key: 'ops', title: '操作', width: 220, align: 'right' },
]

const rows = computed(() => data.value?.items ?? [])
const presets = computed(() => data.value?.presets ?? [])

/* ---------------- 搜索 / 筛选 / 排序 ---------------- */
const search = ref('')
const enableFilter = ref('all')
const sort = ref<SortState | null>(null)
const enableOptions = [
  { label: '全部状态', value: 'all' },
  { label: '已启用', value: 'on' },
  { label: '已停用', value: 'off' },
]

const visibleRows = computed(() => {
  const kw = search.value.trim().toLowerCase()
  const list = rows.value.filter((job) => {
    if (enableFilter.value === 'on' && !job.enabled) return false
    if (enableFilter.value === 'off' && job.enabled) return false
    if (kw !== '') {
      const hay = [job.id, job.name, job.cron, job.last_status].join(' ').toLowerCase()
      if (!hay.includes(kw)) return false
    }
    return true
  })
  return sortRows(list, sort.value, {
    name: (job) => job.name,
    enabled: (job) => job.enabled,
    next_run: (job) => job.next_run_at ?? 0,
    last_run: (job) => job.last_run_at ?? 0,
  })
})

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await scheduleApi.list()
  } catch (e) {
    toast.fromError(e, '加载定时任务失败')
  } finally {
    loading.value = false
  }
}

/* ---------------- 新建 / 编辑 ---------------- */

const dialog = ref(false)
const editing = ref<string | null>(null)
const saving = ref(false)
const formError = ref('')
const previewRuns = ref<string[]>([])
const previewError = ref('')

const form = ref({
  name: '',
  cron: '0 8 * * *',
  enabled: true,
  actionType: 'message' as 'message' | 'handler',
  targetType: 'group' as 'group' | 'private',
  target: '',
  text: '',
  handler: '',
})

function resetForm(): void {
  form.value = {
    name: '',
    cron: '0 8 * * *',
    enabled: true,
    actionType: 'message',
    targetType: 'group',
    target: '',
    text: '',
    handler: '',
  }
  previewRuns.value = []
  previewError.value = ''
  formError.value = ''
}

function openCreate(): void {
  editing.value = null
  resetForm()
  dialog.value = true
}

function openEdit(row: ScheduleJob): void {
  editing.value = row.id
  resetForm()
  form.value.name = row.name
  form.value.cron = row.cron
  form.value.enabled = row.enabled
  form.value.actionType = row.action?.type === 'handler' ? 'handler' : 'message'
  form.value.targetType = row.action?.target_type === 'private' ? 'private' : 'group'
  form.value.target = row.action?.target ?? ''
  form.value.text = row.action?.text ?? ''
  form.value.handler = row.action?.handler ?? ''
  dialog.value = true
}

async function doPreview(): Promise<void> {
  previewError.value = ''
  previewRuns.value = []
  try {
    const res = await scheduleApi.preview(form.value.cron.trim())
    if (!res.ok) {
      previewError.value = res.msg || '表达式无效'
      return
    }
    previewRuns.value = res.runs ?? []
  } catch (e) {
    previewError.value = e instanceof Error ? e.message : '预览失败'
  }
}

async function save(): Promise<void> {
  if (saving.value) return
  formError.value = ''
  if (!form.value.name.trim()) { formError.value = '任务名称不能为空'; return }
  if (!form.value.cron.trim()) { formError.value = 'cron 表达式不能为空'; return }
  if (form.value.actionType === 'message') {
    if (!/^\d{5,12}$/.test(form.value.target.trim())) { formError.value = '目标必须是 5-12 位数字（群号或 QQ）'; return }
    if (!form.value.text.trim()) { formError.value = '消息内容不能为空'; return }
  } else if (!/^[\w\\]+(@|::)\w+$/.test(form.value.handler.trim())) {
    formError.value = '处理方法格式应为 命名空间\\类@方法 或 类::方法'
    return
  }
  saving.value = true
  try {
    const payload: Partial<ScheduleJob> = {
      name: form.value.name.trim(),
      cron: form.value.cron.trim(),
      enabled: form.value.enabled,
      action: form.value.actionType === 'message'
        ? {
          type: 'message',
          target: form.value.target.trim(),
          target_type: form.value.targetType,
          text: form.value.text.trim(),
        }
        : { type: 'handler', handler: form.value.handler.trim() },
    }
    if (editing.value) {
      await scheduleApi.update(editing.value, payload)
    } else {
      await scheduleApi.create(payload)
    }
    dialog.value = false
    toast.ok(editing.value ? '任务已更新' : '任务已创建')
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
    toast.fromError(e, '保存失败')
  } finally {
    saving.value = false
  }
}

async function runNow(row: ScheduleJob): Promise<void> {
  try {
    const res = await scheduleApi.run(row.id)
    if (res.ok) toast.ok('已执行：' + (res.message || '成功'))
    else toast.error('执行失败：' + (res.message || '未知原因'))
    await load()
  } catch (e) {
    toast.fromError(e, '执行失败')
  }
}

async function toggle(row: ScheduleJob, value: boolean): Promise<void> {
  try {
    await scheduleApi.update(row.id, { enabled: Boolean(value) })
    await load()
  } catch (e) {
    toast.fromError(e, '切换失败')
    await load()
  }
}

async function remove(row: ScheduleJob): Promise<void> {
  try {
    await scheduleApi.remove(row.id)
    toast.ok('已删除 ' + row.name)
    await load()
  } catch (e) {
    toast.fromError(e, '删除失败')
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>定时任务</h1>
        <p class="sub">
          按 cron 定时执行：发消息或调用插件里的静态方法。任务存在
          <code>{{ data?.path || 'runtime/schedule/jobs.json' }}</code>
        </p>
      </div>
      <UiButton v-if="canWrite" size="small" type="primary" @click="openCreate">新建任务</UiButton>
    </header>

    <div class="stat-row">
      <div class="stat"><div class="stat-value">{{ data?.stats.total ?? 0 }}</div><div class="stat-label">任务总数</div></div>
      <div class="stat"><div class="stat-value">{{ data?.stats.enabled ?? 0 }}</div><div class="stat-label">已启用</div></div>
      <div class="stat"><div class="stat-value" :class="{ bad: (data?.stats.failed ?? 0) > 0 }">{{ data?.stats.failed ?? 0 }}</div><div class="stat-label">上次失败</div></div>
      <div class="stat wide"><div class="stat-value small">{{ data?.arch || '—' }}</div><div class="stat-label">运行架构</div></div>
    </div>

    <UiCard :padding="'none'">
      <div class="filter-bar">
        <UiInput v-model="search" class="fb-search" placeholder="搜索任务名 / ID / cron…" clearable />
        <UiSelect v-model="enableFilter" :options="enableOptions" class="fb-sel" />
        <span class="fb-count dim">{{ visibleRows.length }} / {{ rows.length }}</span>
        <div class="fb-spacer" />
      </div>
      <UiTable
        :data="visibleRows"
        :columns="columns"
        row-key="id"
        :loading="loading"
        empty-text="没有匹配的定时任务"
        :sort="sort"
        :sort-keys="{ next_run_text: 'next_run', last_run_text: 'last_run', cron: '', action: '' }"
        @update:sort="sort = $event"
      >
        <template #cell-name="{ row }">
          <div class="job-name">{{ (row as ScheduleJob).name }}</div>
          <div class="job-id">{{ (row as ScheduleJob).id }}</div>
        </template>
        <template #cell-cron="{ row }">
          <code class="mono">{{ (row as ScheduleJob).cron }}</code>
        </template>
        <template #cell-action="{ row }">
          <span v-if="(row as ScheduleJob).action?.type === 'message'" class="act">
            发消息 · {{ (row as ScheduleJob).action.target_type === 'private' ? '好友' : '群' }}
            {{ (row as ScheduleJob).action.target }}
          </span>
          <span v-else class="act">调用 {{ (row as ScheduleJob).action?.handler }}</span>
        </template>
        <template #cell-next_run_text="{ row }">
          <span :class="{ overdue: (row as ScheduleJob).overdue }">
            {{ (row as ScheduleJob).next_run_text || '—' }}
          </span>
        </template>
        <template #cell-last_run_text="{ row }">
          <div>{{ (row as ScheduleJob).last_run_text || '—' }}</div>
          <div v-if="(row as ScheduleJob).last_status" class="tiny" :class="(row as ScheduleJob).last_status">
            {{ (row as ScheduleJob).last_status }}
          </div>
        </template>
        <template #cell-enabled="{ row }">
          <UiSwitch
            :model-value="(row as ScheduleJob).enabled"
            :disabled="!canWrite"
            @update:model-value="(v: boolean) => toggle(row as ScheduleJob, v)"
          />
        </template>
        <template #cell-ops="{ row }">
          <div class="ops">
            <UiButton v-if="canWrite" size="small" text @click="runNow(row as ScheduleJob)">执行</UiButton>
            <UiButton v-if="canWrite" size="small" text @click="openEdit(row as ScheduleJob)">编辑</UiButton>
            <UiButton v-if="canWrite" size="small" text type="danger" @click="remove(row as ScheduleJob)">删除</UiButton>
          </div>
        </template>
      </UiTable>
    </UiCard>

    <UiDialog v-model="dialog" :title="editing ? '编辑任务' : '新建定时任务'" width="640px">
      <div class="form-grid">
        <label class="field span-2">
          <span class="field-label">任务名称</span>
          <UiInput v-model="form.name" placeholder="每天早上播报" />
        </label>

        <div class="field span-2">
          <span class="field-label">cron 表达式（分 时 日 月 周）</span>
          <div class="cron-row">
            <UiInput v-model="form.cron" placeholder="0 8 * * *" />
            <UiButton size="small" @click="doPreview">预览</UiButton>
          </div>
          <div class="presets">
            <button
              v-for="p in presets"
              :key="p.expression"
              type="button"
              class="preset"
              @click="form.cron = p.expression; doPreview()"
            >
              {{ p.label }}
            </button>
          </div>
          <div v-if="previewRuns.length" class="preview">
            接下来：{{ previewRuns.join('、') }}
          </div>
          <div v-if="previewError" class="preview bad">{{ previewError }}</div>
        </div>

        <label class="field">
          <span class="field-label">动作</span>
          <UiSelect
            v-model="form.actionType"
            :options="[{ label: '发消息', value: 'message' }, { label: '调用方法', value: 'handler' }]"
          />
        </label>

        <template v-if="form.actionType === 'message'">
          <label class="field">
            <span class="field-label">发送到</span>
            <UiSelect
              v-model="form.targetType"
              :options="[{ label: '群聊', value: 'group' }, { label: '好友', value: 'private' }]"
            />
          </label>
          <label class="field">
            <span class="field-label">{{ form.targetType === 'private' ? 'QQ 号' : '群号' }}</span>
            <UiInput v-model="form.target" placeholder="123456789" />
          </label>
          <label class="field span-2">
            <span class="field-label">消息内容</span>
            <UiInput v-model="form.text" type="textarea" placeholder="支持普通文本" />
          </label>
        </template>

        <label v-else class="field span-2">
          <span class="field-label">静态方法</span>
          <UiInput v-model="form.handler" placeholder="App\Plugins\Help@ping 或 App\Plugins\Help::ping" />
        </label>

        <label class="field inline">
          <UiSwitch v-model="form.enabled" />
          <span class="field-label">启用</span>
        </label>
      </div>
      <div v-if="formError" class="form-error">{{ formError }}</div>
      <template #footer>
        <UiButton size="small" @click="dialog = false">取消</UiButton>
        <UiButton size="small" type="primary" :loading="saving" @click="save">保存</UiButton>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.page-head h1 { margin: 0; font-size: 19px; }
.sub { margin: 4px 0 0; font-size: 12px; color: var(--text-faint); line-height: 1.7; }
.sub code { font-family: var(--font-mono); font-size: 11.5px; }

.stat-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.stat { padding: 12px 14px; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); background: var(--bg-card); }
.stat-value { font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-value.small { font-size: 13px; }
.stat-value.bad { color: var(--danger, #d33); }
.stat-label { margin-top: 2px; font-size: 11px; color: var(--text-faint); }

.job-name { font-size: 12.5px; }
.job-id { font-size: 10.5px; color: var(--text-faint); font-family: var(--font-mono); }
.act { font-size: 12px; color: var(--text-dim); }
.mono { font-family: var(--font-mono); font-size: 11.5px; }
.filter-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-soft);
}
.fb-search { width: 240px; max-width: 100%; }
.fb-sel { width: 128px; }
.fb-spacer { flex: 1; }
.fb-count { font-size: 11.5px; }
@media (max-width: 640px) {
  .fb-search { width: 100%; }
  .fb-sel { width: calc(50% - 4px); }
}
.overdue { color: var(--danger, #d33); }
.tiny { font-size: 10.5px; }
.failed { color: var(--danger, #d33); }
.success, .ok { color: var(--success, #2f9e44); }
.ops { display: inline-flex; gap: 2px; justify-content: flex-end; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field.span-2 { grid-column: span 2; }
.field.inline { flex-direction: row; align-items: center; gap: 8px; }
.field-label { font-size: 11.5px; color: var(--text-dim); }
.cron-row { display: flex; gap: 8px; }
.cron-row > :first-child { flex: 1; }
.presets { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.preset { padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border-soft); background: transparent; color: var(--text-dim); font-size: 11px; cursor: pointer; }
.preset:hover { border-color: var(--accent); color: var(--accent); }
.preview { margin-top: 8px; font-size: 11px; color: var(--text-faint); line-height: 1.7; }
.preview.bad { color: var(--danger, #d33); }
.form-error {
  margin-top: 10px; padding: 7px 10px; border-radius: var(--radius-sm);
  border: 1px solid var(--danger, #d33); color: var(--danger, #d33);
  background: color-mix(in srgb, var(--danger, #d33) 8%, transparent);
  font-size: 12px; line-height: 1.6; user-select: text;
}
</style>
