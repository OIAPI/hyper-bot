<script setup lang="ts">
/**
 * 机器人账号（多账号）。
 *
 * 与「账号连接」页的区别：那页看的是协议运行状态（连接、事件量），
 * 这页管的是「到底有哪几个机器人号」——同一协议可以挂多个号，
 * 事件按 self_id 或 ?account= 路由到对应账号，回包用该账号自己的 API 地址。
 */
import { computed, onMounted, ref } from 'vue'
import { accountApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { BotAccount, BotAccountList } from '@/types/api'
import type { UiColumn } from '@/ui/types'
import { sortRows, type SortState } from '@/utils/sortRows'

const auth = useAuthStore()
const toast = useToastStore()

const data = ref<BotAccountList | null>(null)
const loading = ref(false)
const canWrite = computed(() => auth.can('protocol.write'))

const PROTOCOL_ICON: Record<string, string> = { onebot: '⇄', milky: '◇', qqbot: '⌬' }

const columns: UiColumn[] = [
  { key: 'name', title: '账号', minWidth: 180 },
  { key: 'protocol', title: '协议', width: 130 },
  { key: 'self_id', title: '机器人号码', width: 160 },
  { key: 'endpoint', title: '接入地址', minWidth: 200 },
  { key: 'credential', title: '凭据', width: 110 },
  { key: 'enable', title: '启用', width: 80 },
  { key: 'ops', title: '操作', width: 140, align: 'right' },
]

const DEFAULT_PROTOCOLS: Record<string, string> = {
  onebot: 'OneBot 11',
  milky: 'Milky',
  qqbot: 'QQ 官方机器人',
}

const rows = computed(() => (Array.isArray(data.value?.accounts) ? data.value!.accounts : []))
// 老版本后端没有 stats 字段：这里自己算一份，避免「一个字段缺失整页崩」
const stats = computed(() => {
  const given = data.value?.stats
  if (given && typeof given.total === 'number') return given
  const counts: Record<string, number> = {}
  for (const row of rows.value) counts[row.protocol] = (counts[row.protocol] ?? 0) + 1
  return {
    total: rows.value.length,
    enabled: rows.value.filter((row) => row.enable).length,
    protocols: counts,
  }
})
const protocols = computed(() =>
  data.value?.protocols && Object.keys(data.value.protocols).length ? data.value.protocols : DEFAULT_PROTOCOLS,
)
const protocolOptions = computed(() =>
  Object.entries(protocols.value).map(([value, label]) => ({ label, value })),
)

/* ---------------- 搜索 / 筛选 / 排序 ---------------- */
const search = ref('')
const protocolFilter = ref('')
const enableFilter = ref('all')
const sort = ref<SortState | null>(null)

const protocolFilterOptions = computed(() => [
  { label: '全部协议', value: '' },
  ...protocolOptions.value,
])
const enableFilterOptions = [
  { label: '全部状态', value: 'all' },
  { label: '已启用', value: 'on' },
  { label: '已停用', value: 'off' },
]

const filteredRows = computed(() => {
  const kw = search.value.trim().toLowerCase()
  return rows.value.filter((row) => {
    if (protocolFilter.value !== '' && row.protocol !== protocolFilter.value) return false
    if (enableFilter.value === 'on' && !row.enable) return false
    if (enableFilter.value === 'off' && row.enable) return false
    if (kw !== '') {
      const hay = [row.name, row.id, row.self_id, row.api, row.remark].join(' ').toLowerCase()
      if (!hay.includes(kw)) return false
    }
    return true
  })
})

const visibleRows = computed(() => sortRows(filteredRows.value, sort.value, {
  name: (row) => row.name || row.id,
  protocol: (row) => row.protocol,
  self_id: (row) => row.self_id,
  enable: (row) => row.enable,
}))

const PROTOCOL_COLORS: Record<string, string> = {
  onebot: '#409eff',
  milky: '#67c23a',
  qqbot: '#e6a23c',
}
function protoColor(name: string): string {
  return PROTOCOL_COLORS[name] ?? 'var(--accent)'
}

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await accountApi.list()
  } catch (e) {
    toast.fromError(e, '加载机器人账号失败')
  } finally {
    loading.value = false
  }
}

/* ---------------- 新建 / 编辑 ---------------- */

const dialog = ref(false)
const editing = ref<string | null>(null)
const saving = ref(false)
const formError = ref('')
const form = ref({
  id: '',
  name: '',
  protocol: 'onebot',
  self_id: '',
  api: '',
  token: '',
  forward_url: '',
  forward_token: '',
  app_id: '',
  client_secret: '',
  sandbox: false,
  remark: '',
  enable: true,
})

function openCreate(): void {
  editing.value = null
  form.value = {
    id: '',
    name: '',
    protocol: Object.keys(protocols.value)[0] ?? 'onebot',
    self_id: '',
    api: '',
    token: '',
    forward_url: '',
    forward_token: '',
    app_id: '',
    client_secret: '',
    sandbox: false,
    remark: '',
    enable: true,
  }
  formError.value = ''
  dialog.value = true
}

function openEdit(row: BotAccount): void {
  editing.value = row.id
  formError.value = ''
  form.value = {
    id: row.id,
    name: row.name,
    protocol: row.protocol,
    self_id: row.self_id,
    api: row.api ?? '',
    token: '',
    forward_url: row.forward_url ?? '',
    forward_token: '',
    app_id: row.app_id ?? '',
    client_secret: '',
    sandbox: Boolean(row.sandbox),
    remark: row.remark ?? '',
    enable: row.enable,
  }
  dialog.value = true
}

async function save(): Promise<void> {
  if (saving.value) return
  formError.value = ''
  const isQqbot = form.value.protocol === 'qqbot'
  if (isQqbot) {
    if (!form.value.app_id.trim()) { formError.value = '官方机器人需要 AppID'; return }
    if (!editing.value && !form.value.client_secret.trim()) { formError.value = '官方机器人需要 AppSecret'; return }
  } else {
    if (!form.value.self_id.trim()) { formError.value = '机器人号码不能为空'; return }
    if (form.value.api.trim() && !/^https?:\/\//i.test(form.value.api.trim())) {
      formError.value = 'API 地址要以 http:// 或 https:// 开头'
      return
    }
  }
  saving.value = true
  try {
    const payload: Partial<BotAccount> = {
      name: form.value.name.trim(),
      protocol: form.value.protocol,
      self_id: form.value.self_id.trim(),
      remark: form.value.remark.trim(),
      enable: form.value.enable,
    }
    if (isQqbot) {
      payload.app_id = form.value.app_id.trim()
      payload.sandbox = form.value.sandbox
      // 空 secret 表示「不修改」，复用已保存的 AppSecret
      if (form.value.client_secret) payload.client_secret = form.value.client_secret
    } else {
      payload.api = form.value.api.trim()
      payload.forward_url = form.value.forward_url.trim()
      // 空 token 表示「不修改」，避免编辑时把已有凭据清空
      if (form.value.token) payload.token = form.value.token
      if (form.value.forward_token) payload.forward_token = form.value.forward_token
    }
    if (form.value.id) payload.id = form.value.id
    const res = editing.value
      ? await accountApi.update(editing.value, payload)
      : await accountApi.create(payload)
    data.value = res
    dialog.value = false
    toast.ok(editing.value ? '账号已更新' : '账号已创建')
  } catch (e) {
    formError.value = e instanceof Error ? e.message : '保存失败'
    toast.fromError(e, '保存失败')
  } finally {
    saving.value = false
  }
}

async function toggle(row: BotAccount, value: boolean): Promise<void> {
  try {
    data.value = await accountApi.update(row.id, { enable: Boolean(value) })
  } catch (e) {
    toast.fromError(e, '切换失败')
    await load()
  }
}

async function remove(row: BotAccount): Promise<void> {
  try {
    data.value = await accountApi.remove(row.id)
    toast.ok('已删除 ' + row.id)
  } catch (e) {
    toast.fromError(e, '删除失败')
  }
}

onMounted(load)
</script>

<template>
  <div class="accounts">
    <div class="head">
      <div>
        <div class="title">机器人账号</div>
        <div class="subtle">
          同一协议可以挂多个机器人号，仓库在 <code>{{ data?.path || 'runtime/accounts.json' }}</code>；
          事件按 self_id 自动路由，回包使用该账号自己的接入地址。
        </div>
      </div>
      <div class="head-ops">
        <UiButton size="small" :loading="loading" @click="load">刷新</UiButton>
        <UiButton v-if="canWrite" size="small" type="primary" @click="openCreate">新建账号</UiButton>
      </div>
    </div>

    <div class="summary">
      <div class="sum-kpi">
        <div class="sum-v">{{ stats.total }}</div>
        <div class="sum-l">账号总数</div>
      </div>
      <div class="sum-kpi">
        <div class="sum-v">
          {{ stats.enabled }}<span class="sum-of">/{{ stats.total }}</span>
        </div>
        <div class="sum-l">已启用</div>
      </div>
      <div class="sum-kpi">
        <div class="sum-v">{{ Object.keys(stats.protocols).length }}</div>
        <div class="sum-l">协议种类</div>
      </div>
      <div class="sum-dist">
        <div class="sum-l">协议分布</div>
        <div class="dist-row">
          <span v-for="(count, name) in stats.protocols" :key="name" class="dist-item">
            <i class="dist-dot" :style="{ background: protoColor(String(name)) }" />
            <span class="dist-name">{{ protocols[name] || name }}</span>
            <b class="dist-n">{{ count }}</b>
          </span>
          <span v-if="!Object.keys(stats.protocols).length" class="dim">还没有账号</span>
        </div>
      </div>
    </div>

    <UiCard>
      <div class="filter-bar">
        <UiInput
          v-model="search"
          class="fb-search"
          placeholder="搜索名称 / ID / 号码 / 地址 / 备注…"
          clearable
        />
        <UiSelect v-model="protocolFilter" :options="protocolFilterOptions" class="fb-sel" />
        <UiSelect v-model="enableFilter" :options="enableFilterOptions" class="fb-sel" />
        <span class="fb-count dim">{{ visibleRows.length }} / {{ rows.length }}</span>
        <div class="fb-spacer" />
      </div>
      <UiTable
        :columns="columns"
        :data="visibleRows"
        :loading="loading"
        row-key="id"
        empty-text="没有匹配的机器人账号"
        :sort="sort"
        :sort-keys="{ endpoint: '', credential: '' }"
        @update:sort="sort = $event"
      >
        <template #cell-name="{ row }">
          <div class="acc-name">{{ row.name || row.id }}</div>
          <div class="acc-id">{{ row.id }}</div>
        </template>
        <template #cell-protocol="{ row }">
          <span class="proto">
            <span class="proto-icon">{{ PROTOCOL_ICON[row.protocol] || '•' }}</span>
            {{ protocols[row.protocol] || row.protocol }}
          </span>
        </template>
        <template #cell-self_id="{ row }">
          <span class="mono">{{ row.protocol === 'qqbot' ? (row.app_id || '—') : row.self_id }}</span>
        </template>
        <template #cell-endpoint="{ row }">
          <template v-if="row.protocol === 'qqbot'">
            <div class="mono ellipsis">{{ row.app_id ? 'AppID ' + row.app_id : '未配置 AppID' }}</div>
            <div class="mono ellipsis dim">{{ row.sandbox ? '沙箱环境' : '生产环境' }} · QQ 开放平台</div>
          </template>
          <template v-else>
            <div class="mono ellipsis">{{ row.api || '—' }}</div>
            <div v-if="row.forward_url" class="mono ellipsis dim">{{ row.forward_url }}</div>
          </template>
        </template>
        <template #cell-credential="{ row }">
          <UiTag v-if="row.protocol === 'qqbot' ? row.client_secret_set : row.token_set" type="success" size="small">已配置</UiTag>
          <span v-else class="dim">未配置</span>
        </template>
        <template #cell-enable="{ row }">
          <UiSwitch
            :model-value="row.enable"
            :disabled="!canWrite"
            @update:model-value="(v: boolean) => toggle(row, v)"
          />
        </template>
        <template #cell-ops="{ row }">
          <div class="ops">
            <UiButton v-if="canWrite" size="small" text @click="openEdit(row)">编辑</UiButton>
            <UiButton v-if="canWrite" size="small" text type="danger" @click="remove(row)">删除</UiButton>
          </div>
        </template>
      </UiTable>
    </UiCard>

    <UiDialog v-model="dialog" :title="editing ? '编辑账号 ' + editing : '新建机器人账号'" width="620px">
      <div class="form-grid">
        <label class="field">
          <span class="field-label">协议</span>
          <UiSelect v-model="form.protocol" :options="protocolOptions" :disabled="Boolean(editing)" />
        </label>
        <label class="field">
          <span class="field-label">账号 ID</span>
          <UiInput v-model="form.id" placeholder="留空自动生成，如 onebot-3889" :disabled="Boolean(editing)" />
        </label>
        <label class="field">
          <span class="field-label">显示名称</span>
          <UiInput v-model="form.name" placeholder="主号 / 测试号" />
        </label>
        <template v-if="form.protocol === 'qqbot'">
          <label class="field">
            <span class="field-label">AppID</span>
            <UiInput v-model="form.app_id" class="mono" placeholder="QQ 开放平台的机器人 AppID" />
          </label>
          <label class="field">
            <span class="field-label">AppSecret</span>
            <UiInput
              v-model="form.client_secret"
              type="password"
              :placeholder="editing ? '留空表示不修改' : 'QQ 开放平台的 AppSecret'"
            />
          </label>
          <label class="field inline span-2">
            <UiSwitch v-model="form.sandbox" />
            <span class="field-label">沙箱模式（使用 sandbox.api.sgroup.qq.com）</span>
          </label>
          <div class="field span-2 field-hint">
            官方机器人只需要 AppID + AppSecret，API 域名固定（生产 / 沙箱二选一），
            不需要填 API 地址 / Token；请同时在「账号连接」页打开 QQ 官方机器人总开关。
            回调地址与事件接入方式在 <code>Config.ini</code> 的 <code>[protocol.qqbot]</code> 里配置。
          </div>
        </template>
        <template v-else>
          <label class="field">
            <span class="field-label">机器人号码 self_id</span>
            <UiInput v-model="form.self_id" placeholder="3889012345" />
          </label>
          <label class="field span-2">
            <span class="field-label">API 地址</span>
            <UiInput v-model="form.api" placeholder="http://127.0.0.1:3000" />
          </label>
          <label class="field">
            <span class="field-label">API Token</span>
            <UiInput v-model="form.token" type="password" :placeholder="editing ? '留空表示不修改' : '可选'" />
          </label>
          <label class="field">
            <span class="field-label">正向 WS 地址</span>
            <UiInput v-model="form.forward_url" placeholder="ws://127.0.0.1:6700" />
          </label>
        </template>
        <label class="field span-2">
          <span class="field-label">备注</span>
          <UiInput v-model="form.remark" placeholder="这个号用在哪" />
        </label>
        <label class="field inline">
          <UiSwitch v-model="form.enable" />
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
.accounts { display: flex; flex-direction: column; gap: 14px; }
.head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.title { font-size: 15px; font-weight: 600; }
.subtle { margin-top: 4px; font-size: 12px; color: var(--text-faint); line-height: 1.7; }
.subtle code { font-family: var(--font-mono); font-size: 11.5px; }
.head-ops { display: flex; gap: 8px; }

.summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 2fr);
  gap: 10px;
}
.sum-kpi,
.sum-dist {
  min-width: 0;
  padding: 12px 14px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: var(--surface-raised, var(--surface));
}
.sum-v { font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; }
.sum-of { margin-left: 2px; font-size: 12px; font-weight: 400; color: var(--text-faint); }
.sum-l { font-size: 11px; color: var(--text-faint); }
.dist-row { margin-top: 7px; display: flex; flex-wrap: wrap; gap: 6px 12px; }
.dist-item { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; }
.dist-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex: none; }
.dist-name { color: var(--text-dim); }
.dist-n { font-variant-numeric: tabular-nums; }
@media (max-width: 860px) {
  .summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .sum-dist { grid-column: span 3; }
}
@media (max-width: 520px) {
  .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sum-dist { grid-column: span 2; }
}

.filter-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 0 0 10px;
}
.fb-search { width: 260px; max-width: 100%; }
.fb-sel { width: 128px; }
.fb-spacer { flex: 1; }
.fb-count { font-size: 11.5px; }
@media (max-width: 640px) {
  .fb-search { width: 100%; }
  .fb-sel { width: calc(50% - 4px); }
}

.acc-name { font-size: 12.5px; }
.acc-id { font-size: 10.5px; color: var(--text-faint); font-family: var(--font-mono); }
.proto { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; }
.proto-icon { opacity: 0.7; }
.mono { font-family: var(--font-mono); font-size: 11.5px; }
.ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }
.dim { color: var(--text-faint); }
.ops { display: inline-flex; gap: 2px; justify-content: flex-end; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field.span-2 { grid-column: span 2; }
.field.inline { flex-direction: row; align-items: center; gap: 8px; }
.field-label { font-size: 11.5px; color: var(--text-dim); }
.form-error {
  margin-top: 10px;
  padding: 7px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--danger, #d33);
  color: var(--danger, #d33);
  background: color-mix(in srgb, var(--danger, #d33) 8%, transparent);
  font-size: 12px;
  line-height: 1.6;
  user-select: text;
}
</style>