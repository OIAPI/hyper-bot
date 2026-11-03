<script setup lang="ts">
/**
 * 事件订阅。
 *
 * 决定上游哪些事件被推进框架：message / notice / request / meta_event，
 * 以及消息范围、机器人过滤等。保存后需重启服务生效。
 *
 * 视图只用 UiXxx（框架无关层）；分组标题用 base.css 的 .section-title，
 * 不加卡片重边框，保持轻量。
 */
import { ref, onMounted, computed } from 'vue'
import { subscriptionApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { NoticeGroup, SubscriptionState, SubscriptionExplain, GroupOverride } from '@/types/api'

const toast = useToastStore()
const auth = useAuthStore()
const canWrite = computed(() => auth.can('settings.write'))
const query = ref('')
const adding = ref(false)
const newType = ref('')
const newLabel = ref('')
const newGroup = ref('自定义')
const state = ref<SubscriptionState | null>(null)
const explain = ref<SubscriptionExplain | null>(null)
const source = ref<'v1' | 'v2'>('v2')
const loading = ref(true)
const saving = ref(false)
const dirty = ref(false)

/** 架构提示：webui-only 不接事件流，订阅写得再对也收不到事件 */
const arch = ref<string>('')
const receivesEvents = ref<boolean | null>(null)
const archHint = ref<string | null>(null)

/** 按群覆盖 */
const newGroupId = ref('')
const newGroupMute = ref(true)
const addingGroup = ref(false)
const busyGroup = ref('')
const groupDialog = ref(false)
const editingGroup = ref('')
const editingTypes = ref<string[]>([])

async function load(): Promise<void> {
  loading.value = true
  try {
    const [data, exp] = await Promise.all([subscriptionApi.get(), subscriptionApi.explain()])
    state.value = data.subscription
    source.value = data.configured_from
    arch.value = data.arch ?? ''
    receivesEvents.value = data.receives_events ?? null
    archHint.value = data.arch_hint ?? null
    // 兼容旧后端：细分开关字段缺失时按「全收」处理，避免模板里再判空
    data.subscription.request_types ??= []
    data.subscription.meta_event_types ??= []
    data.subscription.group_overrides ??= {}
    explain.value = exp
  } catch (e) {
    toast.fromError(e, '加载订阅配置失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function markDirty(): void {
  dirty.value = true
}

function toggleNoticeType(key: string): void {
  if (!state.value) return
  const types = state.value.notice_types
  const idx = types.indexOf(key)
  if (idx >= 0) types.splice(idx, 1)
  else types.push(key)
  markDirty()
}

async function save(): Promise<void> {
  if (!state.value) return
  saving.value = true
  try {
    await subscriptionApi.update({
      message: state.value.message,
      notice: state.value.notice,
      request: state.value.request,
      meta_event: state.value.meta_event,
      message_scope: state.value.message_scope,
      notice_types: state.value.notice_types,
      ignore_self: state.value.ignore_self,
      ignore_robots: state.value.ignore_robots,
      robot_prefix: state.value.robot_prefix,
      request_types: state.value.request_types,
      meta_event_types: state.value.meta_event_types,
    })
    toast.ok('订阅已保存，重启后生效')
    dirty.value = false
    await load()
  } catch (e) {
    toast.fromError(e, '保存失败')
  } finally {
    saving.value = false
  }
}

/** 分区目录；后端没返回 groups 时把扁平目录包成一组，保证老数据也能看 */
const allGroups = computed<NoticeGroup[]>(() => {
  const s = state.value
  if (!s) return []
  if (s.notice_groups?.length) return s.notice_groups
  const flat = s.notice_catalog ?? {}
  return Object.keys(flat).length ? [{ title: '通知类型', types: flat }] : []
})

/** 搜索过滤：按类型名或中文名匹配，空组自动隐藏 */
const noticeGroups = computed<NoticeGroup[]>(() => {
  const q = query.value.trim().toLowerCase()
  if (q === '') return allGroups.value
  return allGroups.value
    .map((g) => {
      const types: Record<string, string> = {}
      for (const [key, label] of Object.entries(g.types)) {
        if (key.toLowerCase().includes(q) || label.toLowerCase().includes(q)) types[key] = label
      }
      return { ...g, types }
    })
    .filter((g) => Object.keys(g.types).length > 0)
})

const totalTypes = computed<number>(() => Object.keys(state.value?.notice_catalog ?? {}).length)

const SOURCE_LABEL: Record<string, string> = {
  builtin: '内置',
  plugin: '插件',
  custom: '自定义',
}

/** 目录里出现过的全部键（用于识别「配置里有、目录里没有」的旧值） */
const knownTypes = computed<string[]>(() =>
  noticeGroups.value.flatMap((g) => Object.keys(g.types)),
)

/** 配置里存在但目录未收录的类型：仍然显示出来，否则用户看不到它开着 */
const extraTypes = computed<string[]>(() =>
  (state.value?.notice_types ?? []).filter((k) => !knownTypes.value.includes(k)),
)

/** 通知通道开着但一个类型都没选：实际等于全关，必须显式提示 */
const noticeEmpty = computed<boolean>(
  () => Boolean(state.value?.notice) && (state.value?.notice_types.length ?? 0) === 0,
)

function selectAll(): void {
  if (!state.value) return
  state.value.notice_types = [...knownTypes.value, ...extraTypes.value]
  markDirty()
}

function clearAll(): void {
  if (!state.value) return
  state.value.notice_types = []
  markDirty()
}

function selectGroup(group: NoticeGroup, on: boolean): void {
  if (!state.value) return
  const keys = Object.keys(group.types)
  const rest = state.value.notice_types.filter((k) => !keys.includes(k))
  state.value.notice_types = on ? [...rest, ...keys] : rest
  markDirty()
}

/** 面板里添加一个自定义订阅类型，添加后立刻出现在目录里并可勾选 */
async function addType(): Promise<void> {
  const type = newType.value.trim()
  if (type === '') {
    toast.warn('请填写类型名')
    return
  }
  adding.value = true
  try {
    await subscriptionApi.addCatalogItem({
      type,
      label: newLabel.value.trim(),
      group: newGroup.value.trim() || '自定义',
    })
    newType.value = ''
    newLabel.value = ''
    toast.ok('已添加订阅类型')
    await load()
  } catch (e) {
    toast.fromError(e, '添加失败')
  } finally {
    adding.value = false
  }
}

async function removeType(type: string): Promise<void> {
  try {
    await subscriptionApi.removeCatalogItem(type)
    if (state.value) {
      state.value.notice_types = state.value.notice_types.filter((k) => k !== type)
    }
    toast.ok('已删除订阅类型')
    await load()
  } catch (e) {
    toast.fromError(e, '删除失败')
  }
}

/** 细分开关：请求 / 元事件。空 = 全收（与通知白名单的语义相反） */
const requestCatalog = computed<Record<string, string>>(
  () => state.value?.request_catalog ?? { friend: '好友请求', group: '群请求 / 邀请' },
)
const metaEventCatalog = computed<Record<string, string>>(
  () => state.value?.meta_event_catalog ?? { lifecycle: '生命周期', heartbeat: '心跳' },
)

function toggleFine(field: 'request_types' | 'meta_event_types', key: string): void {
  if (!state.value) return
  const list = state.value[field]
  const idx = list.indexOf(key)
  if (idx >= 0) list.splice(idx, 1)
  else list.push(key)
  markDirty()
}

function setFine(field: 'request_types' | 'meta_event_types', keys: string[]): void {
  if (!state.value) return
  state.value[field] = [...keys]
  markDirty()
}

/** 按群覆盖列表 */
const overrideList = computed(() =>
  Object.entries(state.value?.group_overrides ?? {}).map(([groupId, item]) => ({
    groupId,
    mute: item.mute,
    message: item.message,
    notice_types: item.notice_types ?? [],
  })),
)

function openGroupTypes(groupId: string, types: string[]): void {
  editingGroup.value = groupId
  editingTypes.value = [...types]
  groupDialog.value = true
}

async function saveGroupTypes(): Promise<void> {
  const target = overrideList.value.find((g) => g.groupId === editingGroup.value)
  await patchGroup(editingGroup.value, {
    mute: target?.mute ?? false,
    message: target?.message ?? true,
    notice_types: editingTypes.value,
  })
  groupDialog.value = false
}

async function addGroupOverride(): Promise<void> {
  if (!state.value) return
  const gid = newGroupId.value.trim()
  if (gid === '') {
    toast.warn('请填写群号')
    return
  }
  addingGroup.value = true
  try {
    const res = await subscriptionApi.setGroupOverride(gid, {
      mute: newGroupMute.value,
      message: true,
      notice_types: [],
    })
    state.value.group_overrides = res.groups
    newGroupId.value = ''
    toast.ok('已保存该群的覆盖配置')
  } catch (e) {
    toast.fromError(e, '保存失败')
  } finally {
    addingGroup.value = false
  }
}

async function patchGroup(groupId: string, patch: Partial<GroupOverride>): Promise<void> {
  if (!state.value) return
  busyGroup.value = groupId
  try {
    const res = await subscriptionApi.setGroupOverride(groupId, patch)
    state.value.group_overrides = res.groups
    toast.ok('已更新群 ' + groupId)
  } catch (e) {
    toast.fromError(e, '更新失败')
  } finally {
    busyGroup.value = ''
  }
}

async function removeGroupOverride(groupId: string): Promise<void> {
  if (!state.value) return
  busyGroup.value = groupId
  try {
    const res = await subscriptionApi.removeGroupOverride(groupId)
    state.value.group_overrides = res.groups
    toast.ok('已恢复该群跟随全局')
  } catch (e) {
    toast.fromError(e, '删除失败')
  } finally {
    busyGroup.value = ''
  }
}

const SCOPES = [
  { value: 'group', label: '仅群聊' },
  { value: 'private', label: '仅私聊' },
  { value: 'all', label: '全部' },
]

const CHANNELS = [
  { key: 'message', label: '消息 message', desc: '群消息、私聊消息' },
  { key: 'notice', label: '通知 notice', desc: '入群、退群、撤回等' },
  { key: 'request', label: '请求 request', desc: '加好友、加群请求' },
  { key: 'meta_event', label: '元事件 meta_event', desc: '心跳、生命周期' },
] as const
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>事件订阅</h1>
        <p class="sub">决定哪些上游事件被推进框架；保存后重启生效。</p>
      </div>
      <div class="row gap-8">
        <UiTag size="small" :type="source === 'v2' ? 'ok' : 'warn'">
          配置来源：{{ source === 'v2' ? 'v2 新键' : 'v1 兼容键' }}
        </UiTag>
        <UiTag v-if="dirty" size="small" type="warn">未保存</UiTag>
        <UiButton
          size="small"
          type="primary"
          :disabled="!dirty || !state"
          :loading="saving"
          @click="save"
        >
          {{ saving ? '保存中…' : '保存' }}
        </UiButton>
      </div>
    </header>

    <UiCard v-if="loading">
      <UiSkeleton :rows="6" animated />
    </UiCard>

    <template v-else-if="state">
      <UiAlert v-if="receivesEvents === false" type="warning" :closable="false" class="arch-warn">
        {{ archHint || '当前架构不接收协议事件流，下面的订阅配置保存后不会生效。' }}
      </UiAlert>

      <div class="section-title">事件通道</div>
      <UiCard>
        <div class="chan-grid">
          <div v-for="c in CHANNELS" :key="c.key" class="chan-item">
            <UiSwitch v-model="state[c.key]" @change="markDirty" />
            <div class="chan-text">
              <div class="chan-label">{{ c.label }}</div>
              <div class="chan-desc">{{ c.desc }}</div>
            </div>
          </div>
        </div>

        <UiDivider />

        <UiForm label-width="96px" label-position="left">
          <UiFormItem label="消息范围">
            <UiSelect
              v-model="state.message_scope"
              :options="SCOPES"
              class="w-narrow"
              @change="markDirty"
            />
          </UiFormItem>

          <UiFormItem label="过滤规则">
            <div class="filter-row">
              <UiCheckbox v-model="state.ignore_self" label="忽略自己发的消息" @change="markDirty" />
              <UiCheckbox v-model="state.ignore_robots" label="忽略其它机器人" @change="markDirty" />
            </div>
          </UiFormItem>

          <UiFormItem v-if="state.ignore_robots" label="机器人前缀">
            <div class="prefix-block">
              <UiInput
                v-model="state.robot_prefix"
                class="w-narrow"
                placeholder="3889,4000"
                @update:model-value="markDirty"
              />
              <div class="field-hint">
                ID 以此开头的账号会被判定为机器人；多个前缀用英文逗号分隔，命中任意一个即忽略
              </div>
            </div>
          </UiFormItem>
        </UiForm>
      </UiCard>

      <div class="section-title">请求与元事件细分</div>
      <UiCard>
        <div class="fine-block">
          <div class="fine-head">
            <span class="fine-title">请求 request</span>
            <button type="button" class="link-btn" @click="setFine('request_types', Object.keys(requestCatalog))">全选</button>
            <button type="button" class="link-btn" @click="setFine('request_types', [])">清空</button>
            <span class="fine-hint">
              {{ state.request_types.length ? '只接收勾选的 ' + state.request_types.length + ' 种' : '未限制：全部请求都接收' }}
            </span>
          </div>
          <div class="notice-grid">
            <button
              v-for="(label, key) in requestCatalog"
              :key="key"
              type="button"
              class="notice-chip"
              :class="{ active: state.request_types.includes(key) }"
              @click="toggleFine('request_types', key)"
            >
              <span class="notice-key">{{ key }}</span>
              <span class="notice-label">{{ label }}</span>
            </button>
          </div>
        </div>

        <UiDivider />

        <div class="fine-block">
          <div class="fine-head">
            <span class="fine-title">元事件 meta_event</span>
            <button type="button" class="link-btn" @click="setFine('meta_event_types', Object.keys(metaEventCatalog))">全选</button>
            <button type="button" class="link-btn" @click="setFine('meta_event_types', [])">清空</button>
            <span class="fine-hint">
              {{ state.meta_event_types.length ? '只接收勾选的 ' + state.meta_event_types.length + ' 种' : '未限制：全部元事件都接收' }}
            </span>
          </div>
          <div class="notice-grid">
            <button
              v-for="(label, key) in metaEventCatalog"
              :key="key"
              type="button"
              class="notice-chip"
              :class="{ active: state.meta_event_types.includes(key) }"
              @click="toggleFine('meta_event_types', key)"
            >
              <span class="notice-key">{{ key }}</span>
              <span class="notice-label">{{ label }}</span>
            </button>
          </div>
        </div>
      </UiCard>

      <div class="section-title">
        按群覆盖
        <span class="count">{{ overrideList.length }} 个群</span>
      </div>
      <UiCard>
        <div class="field-hint group-hint">
          不在列表里的群跟随上面的全局配置。静音 = 该群任何事件都不推进框架；通知白名单 = 该群只收勾选的通知类型。
        </div>

        <div v-if="canWrite" class="notice-add">
          <UiInput v-model="newGroupId" class="add-type" placeholder="群号，如 123456789" />
          <UiCheckbox v-model="newGroupMute" label="静音" />
          <UiButton size="small" :loading="addingGroup" @click="addGroupOverride">添加群覆盖</UiButton>
        </div>

        <div v-if="overrideList.length" class="override-list">
          <div v-for="g in overrideList" :key="g.groupId" class="override-row">
            <span class="override-id">{{ g.groupId }}</span>
            <span class="override-tag" :class="{ on: g.mute }">{{ g.mute ? '静音' : '不静音' }}</span>
            <span class="override-hint">
              {{ g.message ? '接收消息' : '不接收消息' }} · 通知
              {{ g.notice_types.length ? '白名单 ' + g.notice_types.length + ' 项' : '跟随全局' }}
            </span>
            <UiSwitch
              v-if="canWrite"
              :model-value="g.mute"
              :disabled="busyGroup === g.groupId"
              @update:model-value="(v: boolean) => patchGroup(g.groupId, { mute: Boolean(v), message: g.message, notice_types: g.notice_types })"
            />
            <UiButton v-if="canWrite" size="small" text @click="openGroupTypes(g.groupId, g.notice_types)">
              通知白名单
            </UiButton>
            <UiButton v-if="canWrite" size="small" text type="danger" @click="removeGroupOverride(g.groupId)">
              删除
            </UiButton>
          </div>
        </div>
        <UiEmpty v-else description="还没有按群覆盖，所有群都跟随全局配置" />
      </UiCard>

      <div class="section-title">
        通知类型
        <span class="count">已选 {{ state.notice_types.length }} / {{ totalTypes }}</span>
      </div>
      <UiCard>
        <UiAlert v-if="noticeEmpty" type="warning" :closable="false" class="notice-warn">
          通知通道已开启，但一个类型都没勾选 —— 所有通知都会被丢弃。点「全选」或至少勾选一个。
        </UiAlert>

        <div class="notice-toolbar">
          <UiInput
            v-model="query"
            class="notice-search"
            placeholder="搜索类型或名称…"
            clearable
          />
          <UiButton size="small" @click="selectAll">全选</UiButton>
          <UiButton size="small" @click="clearAll">清空</UiButton>
          <span v-if="state.notice_stats" class="notice-hint">
            内置 {{ state.notice_stats.builtin ?? 0 }} · 插件 {{ state.notice_stats.plugin ?? 0 }} ·
            自定义 {{ state.notice_stats.custom ?? 0 }}
          </span>
        </div>

        <div v-if="canWrite" class="notice-add">
          <UiInput v-model="newType" class="add-type" placeholder="类型名，如 my_event" />
          <UiInput v-model="newLabel" class="add-label" placeholder="显示名，如 自定义事件" />
          <UiInput v-model="newGroup" class="add-group" placeholder="分组，默认「自定义」" />
          <UiButton size="small" :loading="adding" @click="addType">添加</UiButton>
        </div>

        <div v-for="g in noticeGroups" :key="g.id || g.title" class="notice-group">
          <div class="notice-group-head">
            <span class="notice-group-title">{{ g.title }}</span>
            <span v-if="g.source && g.source !== 'builtin'" class="source-tag">
              {{ SOURCE_LABEL[g.source] }}
            </span>
            <button type="button" class="link-btn" @click="selectGroup(g, true)">全选</button>
            <button type="button" class="link-btn" @click="selectGroup(g, false)">清空</button>
          </div>
          <div class="notice-grid">
            <button
              v-for="(label, key) in g.types"
              :key="key"
              type="button"
              class="notice-chip"
              :class="{ active: state.notice_types.includes(key) }"
              @click="toggleNoticeType(key)"
            >
              <span class="notice-key">{{ key }}</span>
              <span class="notice-label">{{ label }}</span>
              <span
                v-if="canWrite && g.source === 'custom'"
                class="chip-del"
                title="删除该订阅类型"
                @click.stop="removeType(key)"
                >×</span
              >
            </button>
          </div>
        </div>

        <div v-if="extraTypes.length" class="notice-group">
          <div class="notice-group-head">
            <span class="notice-group-title">配置中存在、目录未收录</span>
            <span class="notice-group-hint">多为老版本或新协议写入的值，可点掉</span>
          </div>
          <div class="notice-grid">
            <button
              v-for="key in extraTypes"
              :key="key"
              type="button"
              class="notice-chip active"
              @click="toggleNoticeType(key)"
            >
              <span class="notice-key">{{ key }}</span>
              <span class="notice-label">未知类型</span>
            </button>
          </div>
        </div>

        <UiEmpty
          v-if="!noticeGroups.length"
          :description="query ? '没有匹配的类型' : '没有可配置的通知类型'"
        />
      </UiCard>

      <div v-if="explain" class="section-title">为什么需要订阅</div>
      <UiCard v-if="explain">
        <ul class="why-list">
          <li v-for="(w, i) in explain.why" :key="i">{{ w }}</li>
        </ul>
        <div class="subtle">{{ explain.compatible }}</div>
      </UiCard>

      <UiDialog v-model="groupDialog" :title="'群 ' + editingGroup + ' 的通知白名单'" width="640px">
        <div class="field-hint">
          不勾选任何项 = 该群跟随全局通知白名单；勾选后该群只接收勾选的类型。
        </div>
        <div class="notice-grid dialog-grid">
          <button
            v-for="(label, key) in state.notice_catalog"
            :key="key"
            type="button"
            class="notice-chip"
            :class="{ active: editingTypes.includes(key) }"
            @click="editingTypes.includes(key) ? editingTypes.splice(editingTypes.indexOf(key), 1) : editingTypes.push(key)"
          >
            <span class="notice-key">{{ key }}</span>
            <span class="notice-label">{{ label }}</span>
          </button>
        </div>
        <template #footer>
          <UiButton size="small" @click="groupDialog = false">取消</UiButton>
          <UiButton size="small" type="primary" @click="saveGroupTypes">保存</UiButton>
        </template>
      </UiDialog>
    </template>
  </div>
</template>

<style scoped>
h1 { margin: 0; font-size: 17px; font-weight: 660; letter-spacing: -0.015em; }
p.sub { margin: 3px 0 0; font-size: 12.5px; color: var(--text-faint); }

.section-title { margin: 6px 0 0; }
.w-narrow { width: 220px; }

.chan-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
.chan-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: transparent;
}
.chan-text { min-width: 0; }
.chan-label { font-size: 12.5px; font-weight: 550; }
.chan-desc { font-size: 11px; color: var(--text-faint); line-height: 1.5; }

.filter-row { display: flex; gap: 20px; flex-wrap: wrap; }
.prefix-block { display: flex; flex-direction: column; gap: 6px; }
.field-hint { font-size: 11.5px; color: var(--text-faint); }

.notice-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fill, minmax(178px, 1fr)); }
.notice-chip {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1px;
  padding: 8px 10px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-dim);
  cursor: pointer;
  text-align: left;
  font-family: inherit;
  transition: border-color var(--dur) var(--ease), background var(--dur) var(--ease),
    color var(--dur) var(--ease);
}
.notice-chip:hover { border-color: var(--border-strong); }
.notice-chip.active { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.notice-key { font-family: var(--font-mono); font-size: 11px; }
.notice-label { font-size: 11.5px; }

.count { margin-left: 6px; font-size: 11.5px; font-weight: 400; color: var(--text-faint); }
.notice-warn { margin-bottom: 10px; }
.notice-toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.notice-search { width: 220px; }
.notice-hint { font-size: 11px; color: var(--text-faint); }
.notice-add { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.add-type { width: 190px; }
.add-label { width: 190px; }
.add-group { width: 150px; }
.source-tag {
  padding: 0 5px;
  font-size: 10.5px;
  line-height: 16px;
  border-radius: 3px;
  color: var(--accent);
  background: var(--accent-soft);
}
.notice-chip { position: relative; }
.chip-del {
  position: absolute;
  top: 2px;
  right: 5px;
  font-size: 13px;
  line-height: 1;
  color: var(--text-faint);
}
.chip-del:hover { color: var(--danger, #d33); }
.notice-group { margin-bottom: 14px; }
.notice-group:last-of-type { margin-bottom: 0; }
.notice-group-head { display: flex; align-items: center; gap: 10px; margin-bottom: 7px; }
.notice-group-title { font-size: 12px; font-weight: 550; color: var(--text-dim); }
.notice-group-hint { font-size: 11px; color: var(--text-faint); }
.link-btn {
  border: 0;
  background: none;
  padding: 0;
  font-size: 11.5px;
  color: var(--accent);
  cursor: pointer;
  font-family: inherit;
}

.arch-warn { margin-bottom: 10px; }

.fine-block { display: flex; flex-direction: column; gap: 8px; }
.fine-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fine-title { font-size: 12.5px; font-weight: 550; color: var(--text-dim); }
.fine-hint { font-size: 11px; color: var(--text-faint); }

.group-hint { margin-bottom: 10px; display: block; }
.override-list { display: flex; flex-direction: column; gap: 6px; }
.override-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  flex-wrap: wrap;
}
.override-id { font-family: var(--font-mono); font-size: 12px; color: var(--text-dim); }
.override-tag {
  padding: 0 6px;
  font-size: 10.5px;
  line-height: 17px;
  border-radius: 3px;
  border: 1px solid var(--border-soft);
  color: var(--text-faint);
}
.override-tag.on { color: var(--danger, #d33); border-color: currentColor; }
.override-hint { font-size: 11px; color: var(--text-faint); flex: 1 1 auto; }
.dialog-grid { margin-top: 10px; max-height: 320px; overflow-y: auto; }

.why-list { margin: 0; padding-left: 18px; font-size: 12.5px; line-height: 1.85; color: var(--text-dim); }
.subtle { margin-top: 10px; font-size: 11.5px; color: var(--text-faint); }
</style>
