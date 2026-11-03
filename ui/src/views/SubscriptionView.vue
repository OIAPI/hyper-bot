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
import type { SubscriptionState, SubscriptionExplain } from '@/types/api'

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

const noticeCatalog = computed(() => (state.value?.notice_catalog ?? {}) as Record<string, string>)

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

      <div class="section-title">通知类型</div>
      <UiCard>
        <div class="notice-grid">
          <button
            v-for="(label, key) in noticeCatalog"
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
        <UiEmpty v-if="!Object.keys(noticeCatalog).length" description="没有可配置的通知类型" />
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

.why-list { margin: 0; padding-left: 18px; font-size: 12.5px; line-height: 1.85; color: var(--text-dim); }
.subtle { margin-top: 10px; font-size: 11.5px; color: var(--text-faint); }
</style>
