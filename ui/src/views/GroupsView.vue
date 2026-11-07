<script setup lang="ts">
/**
 * 群组管理。
 *
 * 群列表来自框架的群缓存；「状态」是整群总开关，「插件开关」按插件粒度
 * 控制该群是否响应。两者都直接写配置并热重载，不需要额外保存动作。
 *
 * 视图只用 UiXxx（框架无关层）：表格走 UiTable 的 #cell-<key> 插槽，
 * 移除确认用 UiPopconfirm，图标用纯 SVG 图标（与界面框架无关）。
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Search, Plus } from '@element-plus/icons-vue'
import { groupApi, pluginApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { scopedAccount, setScopedAccount } from '@/stores/accountScope'
import { formatTime } from '@/utils/format'
import type { Group, Plugin } from '@/types/api'
import type { UiColumn } from '@/ui/types'
import { sortRows, type SortState } from '@/utils/sortRows'

const toast = useToastStore()

/** 表格插槽给到的行是宽松类型，这里收敛成具体类型 */
function asGroup(row: unknown): Group {
  return row as Group
}

const loading = ref(true)
const items = ref<Group[]>([])
const plugins = ref<Plugin[]>([])
const query = ref('')
const busy = ref(false)
const selectedAccount = scopedAccount
const accounts = ref<Array<{ id: string; name: string; protocol: string }>>([])
const accountOptions = computed(() =>
  accounts.value.map((a) => ({
    label: a.name ? a.name + '（' + a.id + '）' : a.id,
    value: a.id,
  })),
)
const selectedAccountMeta = computed(() => accounts.value.find((a) => a.id === selectedAccount.value))
const canSyncGroups = computed(() => {
  const protocol = selectedAccountMeta.value?.protocol ?? 'onebot'
  return protocol === 'onebot' || protocol === 'milky' || protocol === 'qqbot'
})
const isOfficialAccount = computed(() => selectedAccountMeta.value?.protocol === 'qqbot')
watch(selectedAccount, (v) => {
  setScopedAccount(v)
  void load()
})

/* ---------------- 筛选 / 排序 ---------------- */
const statusFilter = ref('all')
const sort = ref<SortState | null>(null)
const statusOptions = [
  { label: '全部状态', value: 'all' },
  { label: '已启用', value: 'on' },
  { label: '已停用', value: 'off' },
]
const visibleItems = computed(() => {
  const list = statusFilter.value === 'all'
    ? items.value
    : items.value.filter((g) => (statusFilter.value === 'on' ? g.status : !g.status))
  return sortRows(list, sort.value, {
    group_id: (g) => g.group_id,
    name: (g) => g.name,
    plugins: (g) => g.plugins_on,
    status: (g) => g.status,
    updated_at: (g) => g.updated_at,
  })
})

const manage = ref<Group | null>(null)
const pluginBusy = ref<string | null>(null)
const metaName = ref('')
const metaNote = ref('')
const metaTags = ref('')
const metaSaving = ref(false)

function openManage(g: Group): void {
  manage.value = g
  metaName.value = g.name ?? ''
  metaNote.value = g.note ?? ''
  metaTags.value = (g.tags ?? []).join(', ')
}

async function saveMeta(): Promise<void> {
  if (!manage.value || metaSaving.value) return
  metaSaving.value = true
  try {
    const tags = metaTags.value
      .split(/[,，\s]+/)
      .map((v) => v.trim())
      .filter((v) => v !== '')
    const updated = await groupApi.updateMeta(
      manage.value.group_id,
      { name: metaName.value.trim(), note: metaNote.value.trim(), tags },
      selectedAccount.value || undefined,
    )
    applyUpdate(updated)
    toast.ok('群资料已保存')
  } catch (e) {
    toast.fromError(e, '保存群资料失败')
  } finally {
    metaSaving.value = false
  }
}

const syncing = ref(false)

/** 协议端支持 get_group_list，主动拉一次能补上「没发过言的群」的名字 */
async function syncNames(): Promise<void> {
  if (!canSyncGroups.value) {
    toast.warn('该协议不支持同步群列表')
    return
  }
  if (syncing.value) return
  syncing.value = true
  try {
    const res = await groupApi.sync(selectedAccount.value || undefined)
    toast.ok('已同步 ' + res.synced + ' 个群，共 ' + res.total + ' 个')
    await load()
  } catch (e) {
    toast.fromError(e, '同步失败')
  } finally {
    syncing.value = false
  }
}

const addVisible = ref(false)
const adding = ref(false)
const addGroupId = ref('')
const addName = ref('')

const columns: UiColumn[] = [
  { key: 'group_id', title: '群号', width: 140 },
  { key: 'name', title: '群名', minWidth: 180 },
  { key: 'plugins', title: '插件', width: 110 },
  { key: 'status', title: '状态', width: 100 },
  { key: 'updated_at', title: '更新时间', width: 160 },
  { key: 'actions', title: '操作', width: 90, align: 'right' },
]

const manageOpen = computed({
  get: () => manage.value !== null,
  set: (v: boolean) => {
    if (!v) manage.value = null
  },
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const [groupRes, pluginRes] = await Promise.all([
      groupApi.list(query.value.trim() || undefined, selectedAccount.value || undefined),
      pluginApi.list(),
    ])
    items.value = groupRes.items ?? []
    plugins.value = pluginRes.items ?? []
    accounts.value = groupRes.accounts ?? accounts.value
    if (!selectedAccount.value && groupRes.account) {
      selectedAccount.value = groupRes.account
    }
  } catch (e) {
    toast.fromError(e, '加载群列表失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
  window.addEventListener('hyper:plugins-changed', onPluginsChanged)
})

onUnmounted(() => {
  window.removeEventListener('hyper:plugins-changed', onPluginsChanged)
})

/** 插件重载/启停后，群页里的插件开关列表也要跟着换新的 */
function onPluginsChanged(): void {
  void load()
}

function applyUpdate(g: Group): void {
  const i = items.value.findIndex((x) => x.group_id === g.group_id)
  if (i >= 0) items.value[i] = g
  if (manage.value && manage.value.group_id === g.group_id) manage.value = g
}

async function toggleStatus(g: Group): Promise<void> {
  busy.value = true
  try {
    const updated = await groupApi.toggleStatus(g.group_id, selectedAccount.value || undefined)
    applyUpdate(updated)
    toast.ok(updated.status ? '已开启该群' : '已关闭该群')
  } catch (e) {
    toast.fromError(e, '切换失败')
  } finally {
    busy.value = false
  }
}

async function togglePlugin(g: Group, p: Plugin): Promise<void> {
  // 全局停用的插件在群里改开关没有意义（钩子根本没注册）
  if (p.enabled === false) {
    toast.warn('该插件已在「插件管理」里全局停用，先在那里打开')
    return
  }
  const next = !pluginOn(g, p)
  pluginBusy.value = p.class
  try {
    const updated = await groupApi.togglePlugin(g.group_id, p.class, next, selectedAccount.value || undefined)
    applyUpdate(updated)
    toast.ok(next ? '已在本群开启 ' + p.name : '已在本群关闭 ' + p.name)
  } catch (e) {
    toast.fromError(e, '切换插件失败')
  } finally {
    pluginBusy.value = null
  }
}

/** 有效状态 = 全局启用 && 本群没关（和 Dispatcher 的判定保持一致） */
function pluginOn(g: Group, p: Plugin): boolean {
  return p.enabled !== false && g.worker[p.class] !== false
}

async function submitAdd(): Promise<void> {
  if (!addGroupId.value.trim()) {
    toast.warn('请填写群号')
    return
  }
  adding.value = true
  try {
    await groupApi.create({
      group_id: addGroupId.value.trim(),
      name: addName.value.trim(),
      account: selectedAccount.value || undefined,
    })
    toast.ok('已添加')
    addVisible.value = false
    addGroupId.value = ''
    addName.value = ''
    await load()
  } catch (e) {
    toast.fromError(e, '添加失败')
  } finally {
    adding.value = false
  }
}

async function remove(g: Group): Promise<void> {
  try {
    await groupApi.destroy(g.group_id, selectedAccount.value || undefined)
    toast.ok('已移除')
    manage.value = null
    await load()
  } catch (e) {
    toast.fromError(e, '移除失败')
  }
}

async function confirmRemove(): Promise<void> {
  if (manage.value) await remove(manage.value)
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>群组</h1>
        <p class="sub">
          群配置按机器人账号隔离；先选择账号，再管理该账号下的群与插件开关。
          <template v-if="isOfficialAccount">
            官方机器人没有整表群列表接口，也不会调用受限接口；群通过消息事件发现，
            点「刷新群列表」只按消息记录更新，群名可在「管理」里手动填写。
          </template>
        </p>
      </div>
      <div class="row gap-8">
        <UiSelect v-model="selectedAccount" :options="accountOptions" class="account-select" />
        <UiInput
          v-model="query"
          size="small"
          class="search"
          placeholder="搜索群号或群名…"
          clearable
          @enter="load"
          @clear="load"
        >
          <template #prefix><UiIcon><Search /></UiIcon></template>
        </UiInput>
        <UiButton size="small" @click="load">搜索</UiButton>
        <UiButton size="small" :loading="syncing" :disabled="!canSyncGroups" @click="syncNames">
          {{ isOfficialAccount ? '刷新群列表' : '同步群名' }}
        </UiButton>
        <UiTag size="small" type="plain">{{ items.length }} 个群</UiTag>
        <UiButton size="small" type="primary" @click="addVisible = true">
          <template #icon><UiIcon><Plus /></UiIcon></template>
          添加群
        </UiButton>
      </div>
    </header>

    <UiCard>
      <UiSkeleton v-if="loading" :rows="5" animated />

      <template v-else>
        <div class="filter-bar">
          <UiTag size="small" type="plain">{{ visibleItems.length }} / {{ items.length }} 个群</UiTag>
          <UiSelect v-model="statusFilter" :options="statusOptions" class="fb-sel" />
          <div class="fb-spacer" />
        </div>

        <UiTable
          v-if="visibleItems.length"
          :data="visibleItems"
          :columns="columns"
          row-key="group_id"
          :sort="sort"
          @update:sort="sort = $event"
        >
          <template #cell-group_id="{ row }">
            <span class="mono">{{ asGroup(row).group_id }}</span>
          </template>
          <template #cell-name="{ row }">
            <span v-if="asGroup(row).name">{{ asGroup(row).name }}</span>
            <span v-else class="dim">未命名（可在「管理」里补群名）</span>
          </template>
          <template #cell-plugins="{ row }">
            <span class="mono dim">
              {{ asGroup(row).plugins_on }} / {{ asGroup(row).plugins_total }}
            </span>
          </template>
          <template #cell-status="{ row }">
            <UiSwitch
              :model-value="asGroup(row).status"
              :disabled="busy"
              @update:model-value="toggleStatus(asGroup(row))"
            />
          </template>
          <template #cell-updated_at="{ row }">
            <span class="tiny dim">
              {{ asGroup(row).updated_at ? formatTime(asGroup(row).updated_at) : '—' }}
            </span>
          </template>
          <template #cell-actions="{ row }">
            <UiButton size="small" type="text" @click="openManage(asGroup(row))">管理</UiButton>
          </template>
        </UiTable>

        <UiEmpty
          v-else
          :description="items.length
            ? '没有符合筛选条件的群。'
            : '还没有纳管任何群。机器人收到群消息后会自动出现在这里，也可以手动添加。'"
        />
      </template>
    </UiCard>

    <UiDialog v-model="manageOpen" :title="'群 ' + (manage?.group_id ?? '')" width="560px">
      <div v-if="manage" class="manage">
        <div class="meta-form">
          <label class="meta-field">
            <span class="field-label">群名</span>
            <UiInput v-model="metaName" placeholder="官方群没有名称接口，可在这里手动命名" />
          </label>
          <label class="meta-field">
            <span class="field-label">备注</span>
            <UiInput v-model="metaNote" placeholder="例如：内测群 / 客户群" />
          </label>
          <label class="meta-field">
            <span class="field-label">标签（逗号分隔）</span>
            <UiInput v-model="metaTags" placeholder="技术, 内测" />
          </label>
          <div class="meta-actions">
            <UiButton size="small" type="primary" :loading="metaSaving" @click="saveMeta">保存群资料</UiButton>
          </div>
        </div>

        <UiDivider />

        <div class="row-between">
          <span class="field-label">该群启用状态</span>
          <UiSwitch
            :model-value="manage.status"
            :disabled="busy"
            @update:model-value="toggleStatus(manage)"
          />
        </div>

        <UiDivider />

        <div class="field-label">插件开关</div>
        <div class="plugin-list">
          <div v-for="p in plugins" :key="p.class" class="plugin-row">
            <div class="plugin-text">
              <div class="plugin-name">{{ p.name }}</div>
              <div class="plugin-desc">
                {{ p.description || '（无描述）' }}
                <span v-if="p.enabled === false" class="plugin-off">· 全局已停用</span>
              </div>
            </div>
            <UiSwitch
              :model-value="pluginOn(manage, p)"
              :loading="pluginBusy === p.class"
              :disabled="p.enabled === false"
              @update:model-value="togglePlugin(manage, p)"
            />
          </div>
        </div>
        <UiEmpty v-if="!plugins.length" description="没有可用插件" />
      </div>

      <template #footer>
        <div class="dialog-foot">
          <UiPopconfirm
            :title="'确定从管理列表移除群 ' + (manage?.group_id ?? '') + ' 吗？'"
            confirm-text="移除"
            cancel-text="取消"
            :width="240"
            :disabled="!manage"
            @confirm="confirmRemove"
          >
            <UiButton size="small" type="danger" :disabled="!manage">移除</UiButton>
          </UiPopconfirm>
          <div class="spacer" />
          <UiButton size="small" type="primary" @click="manage = null">关闭</UiButton>
        </div>
      </template>
    </UiDialog>

    <UiDialog v-model="addVisible" title="添加群" width="420px">
      <UiForm label-position="top">
        <UiFormItem label="群号">
          <UiInput v-model="addGroupId" class="mono" placeholder="例如 123456789" />
        </UiFormItem>
        <UiFormItem label="备注名（可选）">
          <UiInput v-model="addName" placeholder="方便识别" />
        </UiFormItem>
      </UiForm>
      <template #footer>
        <div class="row right gap-8">
          <UiButton size="small" @click="addVisible = false">取消</UiButton>
          <UiButton size="small" type="primary" :loading="adding" @click="submitAdd">添加</UiButton>
        </div>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
h1 { margin: 0; font-size: 17px; font-weight: 660; letter-spacing: -0.015em; }
p.sub { margin: 3px 0 0; font-size: 12.5px; color: var(--text-faint); }

/* 头部在窄屏必须能换行，否则「添加群」按钮会被挤出屏幕 */
.page-head { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.page-head > .row { flex-wrap: wrap; flex: 1 1 auto; justify-content: flex-end; }
.search { width: 210px; max-width: 100%; }
@media (max-width: 720px) {
  .page-head > .row { width: 100%; justify-content: flex-start; }
  .search { flex: 1 1 100%; width: 100%; }
}
.plugin-off { color: var(--warn, #b58900); font-size: 11.5px; }
.mono { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
.filter-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 0 0 10px;
}
.fb-sel { width: 128px; }
.fb-spacer { flex: 1; }
@media (max-width: 640px) { .fb-sel { width: calc(50% - 4px); } }
.tiny { font-size: 11.5px; }
.dim { color: var(--text-faint); }

.manage { display: flex; flex-direction: column; gap: 10px; }
.meta-form { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.meta-field { display: flex; flex-direction: column; gap: 5px; }
.meta-field:first-child { grid-column: span 2; }
.meta-actions { grid-column: span 2; display: flex; justify-content: flex-end; }
@media (max-width: 520px) {
  .meta-form { grid-template-columns: 1fr; }
  .meta-field:first-child, .meta-actions { grid-column: span 1; }
}
.row-between { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.plugin-list { display: flex; flex-direction: column; gap: 12px; }
.plugin-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.plugin-text { min-width: 0; }
.plugin-name { font-size: 12.8px; color: var(--text); }
.plugin-desc { font-size: 11.5px; color: var(--text-faint); }

.dialog-foot { display: flex; align-items: center; gap: 8px; }
</style>