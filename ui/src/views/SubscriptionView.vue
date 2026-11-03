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
import { useToastStore } from '@/stores/toast'
import type { NoticeGroup, SubscriptionState, SubscriptionExplain } from '@/types/api'

const toast = useToastStore()
const state = ref<SubscriptionState | null>(null)
const explain = ref<SubscriptionExplain | null>(null)
const source = ref<'v1' | 'v2'>('v2')
const loading = ref(true)
const saving = ref(false)
const dirty = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    const [data, exp] = await Promise.all([subscriptionApi.get(), subscriptionApi.explain()])
    state.value = data.subscription
    source.value = data.configured_from
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
const noticeGroups = computed<NoticeGroup[]>(() => {
  const s = state.value
  if (!s) return []
  if (s.notice_groups?.length) return s.notice_groups
  const flat = s.notice_catalog ?? {}
  return Object.keys(flat).length ? [{ title: '通知类型', types: flat }] : []
})

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
                placeholder="3889"
                @update:model-value="markDirty"
              />
              <div class="field-hint">昵称以此开头的账号会被判定为机器人</div>
            </div>
          </UiFormItem>
        </UiForm>
      </UiCard>

      <div class="section-title">
        通知类型
        <span class="count">已选 {{ state.notice_types.length }}</span>
      </div>
      <UiCard>
        <UiAlert v-if="noticeEmpty" type="warning" :closable="false" class="notice-warn">
          通知通道已开启，但一个类型都没勾选 —— 所有通知都会被丢弃。点「全选」或至少勾选一个。
        </UiAlert>

        <div class="notice-toolbar">
          <UiButton size="small" @click="selectAll">全选</UiButton>
          <UiButton size="small" @click="clearAll">清空</UiButton>
        </div>

        <div v-for="g in noticeGroups" :key="g.title" class="notice-group">
          <div class="notice-group-head">
            <span class="notice-group-title">{{ g.title }}</span>
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

        <UiEmpty v-if="!noticeGroups.length" description="没有可配置的通知类型" />
      </UiCard>

      <div v-if="explain" class="section-title">为什么需要订阅</div>
      <UiCard v-if="explain">
        <ul class="why-list">
          <li v-for="(w, i) in explain.why" :key="i">{{ w }}</li>
        </ul>
        <div class="subtle">{{ explain.compatible }}</div>
      </UiCard>
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
.notice-toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
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

.why-list { margin: 0; padding-left: 18px; font-size: 12.5px; line-height: 1.85; color: var(--text-dim); }
.subtle { margin-top: 10px; font-size: 11.5px; color: var(--text-faint); }
</style>
