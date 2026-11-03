<script setup lang="ts">
/**
 * 账号连接 —— 协议接入配置。
 *
 * 这是旧版 WebUI 最大的缺口：协议页是纯只读的，明明后端有
 * PUT /protocols/{protocol}，前端从来没调用过，只能手改 Config.ini。
 *
 * 这里把每个协议的表单字段显式定义出来，而不是通用渲染整棵配置树——
 * 因为协议配置有强类型语义（开关、地址、密钥、模式三选一），
 * 通用表单既给不出合适的控件，也没法做校验与联动。
 *
 * 视图只用 UiXxx（框架无关层）：卡片头部放在 body 内的 .proto-head，
 * 折叠/复制图标用文本字形，不绑定某个图标库。
 */
import { computed, onMounted, ref } from 'vue'
import { ArrowDown, ArrowRight, CopyDocument } from '@element-plus/icons-vue'
import { protocolApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { copyText } from '@/utils/clipboard'
import type { ProtocolName, ProtocolDetail, ProtocolStatus } from '@/types/api'

const toast = useToastStore()

type FieldType = 'switch' | 'text' | 'password' | 'number' | 'select'

interface FieldDef {
  /** 相对 protocol.{name}. 的键路径 */
  key: string
  label: string
  type: FieldType
  placeholder?: string
  hint?: string
  options?: Array<{ value: string; label: string }>
  /** 显示条件：依赖其它字段的当前值 */
  when?: (v: Record<string, unknown>) => boolean
}

interface ProtocolMeta {
  name: ProtocolName
  title: string
  subtitle: string
  fields: FieldDef[]
}

const META: ProtocolMeta[] = [
  {
    name: 'onebot',
    title: 'OneBot11',
    subtitle: '对接 go-cqhttp / Lagrange / NapCat 等实现端',
    fields: [
      { key: 'enable', label: '启用 OneBot11', type: 'switch' },
      { key: 'forward.enable', label: '正向 WS', type: 'switch', hint: '本框架主动连接实现端' },
      {
        key: 'forward.url',
        label: '正向 WS 地址',
        type: 'text',
        placeholder: 'ws://127.0.0.1:3001',
        when: (v) => Boolean(v['forward.enable']),
      },
      {
        key: 'forward.token',
        label: '正向 Token',
        type: 'password',
        placeholder: 'access_token（可选）',
        when: (v) => Boolean(v['forward.enable']),
      },
      { key: 'reverse.enable', label: '反向 WS', type: 'switch', hint: '实现端连接本框架' },
      {
        key: 'reverse.path',
        label: '反向 WS 路径',
        type: 'text',
        placeholder: '/onebot/v11/ws',
        when: (v) => Boolean(v['reverse.enable']),
      },
      {
        key: 'reverse.token',
        label: '反向 Token',
        type: 'password',
        placeholder: '留空表示不校验',
        when: (v) => Boolean(v['reverse.enable']),
      },
      { key: 'self_id', label: '机器人 QQ 号', type: 'text', placeholder: '留空则从事件自动学习' },
    ],
  },
  {
    name: 'milky',
    title: 'Milky',
    subtitle: 'Milky 协议端 HTTP API + 事件推送',
    fields: [
      { key: 'enable', label: '启用 Milky', type: 'switch' },
      { key: 'api', label: 'API 地址', type: 'text', placeholder: 'http://127.0.0.1:3010' },
      { key: 'token', label: '访问 Token', type: 'password', placeholder: '协议端配置的 token' },
      { key: 'timeout', label: '超时（秒）', type: 'number', placeholder: '30' },
      {
        key: 'event.mode',
        label: '事件接入方式',
        type: 'select',
        options: [
          { value: 'webhook', label: 'WebHook（推荐）' },
          { value: 'ws', label: 'WebSocket' },
          { value: 'sse', label: 'SSE' },
        ],
      },
      {
        key: 'webhook.path',
        label: 'WebHook 路径',
        type: 'text',
        placeholder: '/api/protocol/milky/webhook',
        when: (v) => v['event.mode'] === 'webhook' || v['event_mode'] === 'webhook',
      },
      {
        key: 'ws.url',
        label: 'WS 地址',
        type: 'text',
        placeholder: '留空则从 API 地址推导',
        when: (v) => v['event_mode'] === 'ws',
      },
      {
        key: 'sse.url',
        label: 'SSE 地址',
        type: 'text',
        placeholder: '留空则从 API 地址推导',
        when: (v) => v['event_mode'] === 'sse',
      },
    ],
  },
  {
    name: 'qqbot',
    title: 'QQ 官方机器人',
    subtitle: 'QQ Bot OpenAPI v2',
    fields: [
      { key: 'enable', label: '启用 QQ 官方机器人', type: 'switch' },
      { key: 'app_id', label: 'AppID', type: 'text', placeholder: '开放平台 AppID' },
      { key: 'client_secret', label: 'Client Secret', type: 'password', placeholder: '开放平台密钥' },
      { key: 'api', label: 'API 域名', type: 'text', placeholder: 'https://api.bot.qq.com' },
      { key: 'token_url', label: 'Token 地址', type: 'text', placeholder: 'https://api.bot.qq.com/app/getAppAccessToken' },
      {
        key: 'event.mode',
        label: '事件接入方式',
        type: 'select',
        options: [
          { value: 'ws', label: 'Gateway WebSocket' },
          { value: 'webhook', label: 'WebHook（需公网 HTTPS）' },
        ],
      },
      {
        key: 'webhook.path',
        label: 'WebHook 路径',
        type: 'text',
        placeholder: '/api/protocol/qqbot/webhook',
        when: (v) => v['event_mode'] === 'webhook',
      },
      { key: 'intents', label: 'Intents', type: 'text', placeholder: '留空使用默认', hint: '可填整数或事件名，逗号分隔' },
    ],
  },
]

const loading = ref(true)
const saving = ref<ProtocolName | null>(null)
const statuses = ref<Record<string, ProtocolStatus>>({})
const details = ref<Partial<Record<ProtocolName, ProtocolDetail>>>({})
/** 每个协议的编辑副本：{ 扁平键: 值 } */
const drafts = ref<Partial<Record<ProtocolName, Record<string, unknown>>>>({})
const dirty = ref<Partial<Record<ProtocolName, boolean>>>({})

/** 把后端返回的嵌套配置拍平成点路径键，方便与 FieldDef.key 对应 */
function flatten(input: Record<string, unknown>, prefix = '', out: Record<string, unknown> = {}): Record<string, unknown> {
  for (const [k, v] of Object.entries(input)) {
    const key = prefix ? prefix + '.' + k : k
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
      flatten(v as Record<string, unknown>, key, out)
    } else {
      out[key] = v
    }
  }
  return out
}

/** 后端的 event.mode 在扁平化后是 event.mode，字段定义里用的也是这个，
 *  但 when() 里我们统一补一份 event_mode 方便判断 */
function withAlias(src: Record<string, unknown>): Record<string, unknown> {
  const out = { ...src }
  if ('event.mode' in out) out['event_mode'] = out['event.mode']
  return out
}

/** 发送能力自检：真的调一次 get_login_info，把协议端原话显示出来 */
const testVisible = ref(false)
const testing = ref(false)
const testResult = ref<Record<string, unknown> | null>(null)

async function runTest(): Promise<void> {
  if (testing.value) return
  testing.value = true
  testResult.value = null
  try {
    testResult.value = (await protocolApi.test('onebot')) as unknown as Record<string, unknown>
    testVisible.value = true
  } catch (e) {
    toast.fromError(e, '测试失败')
  } finally {
    testing.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    statuses.value = await protocolApi.list()
    const results = await Promise.all(
      META.map(async (m) => {
        const d = await protocolApi.show(m.name)
        return [m.name, d] as const
      }),
    )
    for (const [name, d] of results) {
      details.value[name] = d
      const flat = flatten(d.config ?? {})
      drafts.value[name] = withAlias(flat)
    }
    dirty.value = {}
  } catch (e) {
    toast.fromError(e, '加载协议配置失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function setField(name: ProtocolName, key: string, value: unknown): void {
  const d = drafts.value[name]
  if (!d) return
  d[key] = value
  if (key === 'event.mode') d['event_mode'] = value
  dirty.value[name] = true
}

function getField(name: ProtocolName, key: string): unknown {
  return drafts.value[name]?.[key]
}

function isDirty(name: ProtocolName): boolean {
  return Boolean(dirty.value[name])
}

async function save(name: ProtocolName): Promise<void> {
  const draft = drafts.value[name]
  if (!draft) return
  saving.value = name
  try {
    // 只提交表单里定义过的字段，避免把只读的派生值写回配置
    const payload: Record<string, unknown> = {}
    for (const f of META.find((m) => m.name === name)!.fields) {
      if (f.key in draft) payload[f.key] = draft[f.key]
    }
    const res = await protocolApi.update(name, payload)
    if (!res.applied.length) {
      toast.warn('没有字段被写入——请检查这些键是否在后端白名单内')
    } else {
      toast.ok('已保存 ' + res.applied.length + ' 项，重启服务后生效')
    }
    dirty.value[name] = false
  } catch (e) {
    toast.fromError(e, '保存失败')
  } finally {
    saving.value = null
  }
}

function reset(name: ProtocolName): void {
  const d = details.value[name]
  if (!d) return
  drafts.value[name] = withAlias(flatten(d.config ?? {}))
  dirty.value[name] = false
}

/** 每个协议自己那一层的配置（用于「当前配置」只读展示） */
function visibleFields(m: ProtocolMeta): FieldDef[] {
  const draft = drafts.value[m.name] ?? {}
  return m.fields.filter((f) => !f.when || f.when(draft))
}

const endpointsOpen = ref<ProtocolName | null>(null)

function showEndpoints(name: ProtocolName): void {
  endpointsOpen.value = endpointsOpen.value === name ? null : name
}

async function copyEndpoint(url: string): Promise<void> {
  const okCopy = await copyText(url)
  okCopy ? toast.ok('已复制') : toast.error('复制失败，请手动选择文本')
}

/** 当前草稿里某个开关是否打开（读的是表单，不是运行状态） */
function fieldOn(name: string, key: string): boolean {
  return Boolean(getField(name as ProtocolName, key))
}

const enabledCount = computed(() =>
  Object.values(statuses.value).filter((s) => s.enabled).length,
)

const totalProtocols = computed(() => Object.keys(statuses.value).length)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>账号连接</h1>
        <p class="sub">协议接入配置，保存在 Config.ini；修改后需重启服务生效。</p>
      </div>
      <div class="row gap-8">
        <UiTag size="small" :type="enabledCount ? 'ok' : 'warn'">
          {{ enabledCount }} / {{ totalProtocols }} 已启用
        </UiTag>
      </div>
    </header>

    <div class="note">
      配置保存在 Config.ini。<b>修改后需重启服务生效</b>（运行架构在进程启动时确定）。
    </div>

    <div v-if="loading" class="proto-grid">
      <UiCard v-for="i in 3" :key="i">
        <UiSkeleton :rows="6" animated />
      </UiCard>
    </div>

    <div v-else class="proto-grid">
      <UiCard v-for="m in META" :key="m.name">
        <div class="proto-body">
          <div class="proto-head">
            <span class="dot" :class="statuses[m.name]?.enabled ? 'ok' : 'off'" />
            <div class="proto-title">
              <div class="proto-name">{{ m.title }}</div>
              <div class="proto-sub">{{ m.subtitle }}</div>
            </div>
            <div class="spacer" />
            <UiTag v-if="isDirty(m.name)" type="warn" size="small">未保存</UiTag>
            <UiButton v-if="m.name === 'onebot'" size="small" :loading="testing" @click="runTest">
              测试发送
            </UiButton>
            <UiButton size="small" :disabled="saving === m.name" @click="reset(m.name)">重置</UiButton>
            <UiButton
              size="small"
              type="primary"
              :disabled="!isDirty(m.name) || saving === m.name"
              @click="save(m.name)"
            >
              {{ saving === m.name ? '保存中…' : '保存' }}
            </UiButton>
          </div>

          <div v-if="statuses[m.name]" class="status-strip">
            <span class="field-label">模式</span>
            <UiTag size="small" type="plain">{{ statuses[m.name].mode || '—' }}</UiTag>
            <template v-if="m.name === 'onebot'">
              <!-- 关闭的通道说「已关闭」，不要显示成「离线」——那会被读成故障 -->
              <UiTag v-if="!fieldOn(m.name, 'forward.enable')" size="small" type="plain">正向 已关闭</UiTag>
              <UiTag
                v-else
                size="small"
                :type="statuses[m.name]?.detail?.forward_running ? 'ok' : 'warn'"
              >
                正向 {{ statuses[m.name]?.detail?.forward_running ? '在线' : '连接中' }}
              </UiTag>

              <UiTag v-if="!fieldOn(m.name, 'reverse.enable')" size="small" type="plain">反向 已关闭</UiTag>
              <UiTag
                v-else
                size="small"
                :type="(statuses[m.name]?.detail?.reverse_sessions ?? 0) > 0 ? 'ok' : 'plain'"
              >
                反向 {{ (statuses[m.name]?.detail?.reverse_sessions ?? 0) > 0
                  ? (statuses[m.name]?.detail?.reverse_sessions + ' 个连接')
                  : '等待连接' }}
              </UiTag>

              <UiTag
                v-if="!fieldOn(m.name, 'reverse.enable') && (statuses[m.name]?.detail?.reverse_sessions ?? 0) > 0"
                size="small"
                type="warn"
              >
                旧连接将在重启后断开
              </UiTag>
            </template>
            <template v-if="m.name === 'milky'">
              <UiTag size="small" type="plain">
                WS {{ statuses[m.name]?.detail?.ws_in ?? 0 }}
              </UiTag>
            </template>
          </div>

          <div class="fields">
            <div v-for="f in visibleFields(m)" :key="f.key" class="field">
              <div v-if="f.type === 'switch'" class="switch-row">
                <span class="field-label">{{ f.label }}</span>
                <UiSwitch
                  :model-value="Boolean(getField(m.name, f.key))"
                  @update:model-value="(v: string | number | boolean) => setField(m.name, f.key, v)"
                />
              </div>

              <template v-else>
                <span class="field-label">{{ f.label }}</span>
                <UiInput
                  v-if="f.type === 'text'"
                  :model-value="String(getField(m.name, f.key) ?? '')"
                  :placeholder="f.placeholder"
                  @update:model-value="(v: string) => setField(m.name, f.key, v)"
                />
                <UiInput
                  v-else-if="f.type === 'password'"
                  type="password"
                  :model-value="String(getField(m.name, f.key) ?? '')"
                  :placeholder="f.placeholder"
                  @update:model-value="(v: string) => setField(m.name, f.key, v)"
                />
                <UiInputNumber
                  v-else-if="f.type === 'number'"
                  :model-value="Number(getField(m.name, f.key) ?? 0)"
                  :min="0"
                  :placeholder="f.placeholder"
                  class="number-input"
                  @update:model-value="(v: number | undefined) => setField(m.name, f.key, v ?? 0)"
                />
                <UiSelect
                  v-else-if="f.type === 'select'"
                  :model-value="String(getField(m.name, f.key) ?? '')"
                  :options="f.options ?? []"
                  class="select-input"
                  @update:model-value="(v: string) => setField(m.name, f.key, v)"
                />
              </template>

              <div v-if="f.hint" class="field-hint">{{ f.hint }}</div>
            </div>
          </div>

          <div class="endpoints">
            <UiButton type="text" size="small" @click="showEndpoints(m.name)">
              <template #icon>
                <UiIcon><component :is="endpointsOpen === m.name ? ArrowDown : ArrowRight" /></UiIcon>
              </template>
              接入地址
            </UiButton>

            <div v-if="endpointsOpen === m.name" class="endpoint-list">
              <div v-for="(ep, i) in (details[m.name]?.endpoints ?? [])" :key="i" class="endpoint">
                <div class="endpoint-label">{{ ep.label }}</div>
                <div class="endpoint-row">
                  <code class="endpoint-url">{{ ep.url }}</code>
                  <UiButton size="small" @click="copyEndpoint(ep.url)">
                    <template #icon><UiIcon><CopyDocument /></UiIcon></template>
                    复制
                  </UiButton>
                </div>
              </div>
              <UiEmpty
                v-if="!(details[m.name]?.endpoints ?? []).length"
                description="当前协议无对外接入地址"
              />
            </div>
          </div>
        </div>
      </UiCard>
    </div>
    <UiDialog v-model="testVisible" title="发送能力自检（get_login_info）" width="560px">
      <div v-if="testResult" class="test-result">
        <div class="test-row">
          <span class="field-label">结果</span>
          <UiTag size="small" :type="testResult.ok ? 'ok' : 'danger'">
            {{ testResult.ok ? '成功' : '失败' }}
          </UiTag>
          <span class="tiny faint">走 {{ testResult.via }} · {{ testResult.ms }}ms</span>
        </div>
        <div class="test-row">
          <span class="field-label">接口地址</span>
          <code class="mono">{{ testResult.api || '（未配置）' }}</code>
        </div>
        <div v-if="!testResult.ok" class="form-hint">
          {{ testResult.hint }}
        </div>
        <pre class="test-json">{{ JSON.stringify(testResult, null, 2) }}</pre>
      </div>
      <template #footer>
        <UiButton size="small" @click="testVisible = false">关闭</UiButton>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
h1 { margin: 0; font-size: 17px; font-weight: 660; letter-spacing: -0.015em; }
p.sub { margin: 3px 0 0; font-size: 12.5px; color: var(--text-faint); }

.proto-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
}
.proto-body { display: flex; flex-direction: column; gap: 14px; min-width: 0; }

.proto-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.proto-title { min-width: 0; }
.proto-name { font-size: 13px; font-weight: 620; letter-spacing: -0.01em; }
.proto-sub { font-size: 11.5px; font-weight: 400; color: var(--text-faint); }

.status-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--border-soft);
}

.fields { display: flex; flex-direction: column; gap: 12px; }
.switch-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }

.number-input, .select-input { width: 100%; }

.endpoints { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.endpoint-list { display: flex; flex-direction: column; gap: 8px; width: 100%; }
.endpoint {
  padding: 9px 11px;
  border: 1px solid var(--border-soft);
  border-radius: var(--radius-sm);
  background: transparent;
}
.endpoint-label { font-size: 11.5px; color: var(--text-faint); }
.endpoint-row { display: flex; align-items: center; gap: 8px; }
.endpoint-url {
  flex: 1;
  min-width: 0;
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--text-dim);
  word-break: break-all;
}
</style>