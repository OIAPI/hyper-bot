<script setup lang="ts">
/**
 * 运行时：架构切换 + PHP 扩展检查。全部控件走 UiXxx 抽象层。
 *
 * 降级说明：每个架构选项带说明文字与「运行中 / 待重启」状态，UiRadioGroup 的
 * options 只有裸文字，表达不了这些内容，因此改为自绘的单选列表（点击即切换）。
 */
import { computed, onMounted, ref } from 'vue'
import { runtimeApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import type { UiColumn } from '@/ui/types'
import type { RuntimeData } from '@/types/api'

const toast = useToastStore()
const data = ref<RuntimeData | null>(null)
const loading = ref(true)
const saving = ref(false)

const columns: UiColumn[] = [
  { key: 'label', title: '扩展', minWidth: 240 },
  { key: 'present', title: '状态', width: 100, align: 'right' },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await runtimeApi.get()
  } catch (e) {
    toast.fromError(e, '加载运行时信息失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function choose(arch: string): Promise<void> {
  if (!data.value || data.value.configured === arch) return
  saving.value = true
  try {
    await runtimeApi.update(arch)
    toast.ok('已保存，重启服务后生效')
    await load()
  } catch (e) {
    toast.fromError(e, '切换失败')
  } finally {
    saving.value = false
  }
}

const EXT_LABELS: Record<string, string> = {
  swoole: 'ext-swoole（常驻协程 / WS 服务端）',
  redis: 'ext-redis（Redis 存储）',
  sodium: 'ext-sodium（QQ 官方验签）',
  curl: 'ext-curl（协议调用）',
}

interface ExtensionRow {
  name: string
  label: string
  present: boolean
}

const extensions = computed<ExtensionRow[]>(() => {
  const map = data.value?.php.extensions ?? {}
  return Object.entries(map).map(([name, present]) => ({
    name,
    label: EXT_LABELS[name] ?? name,
    present,
  }))
})
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>运行时</h1>
        <p class="sub">进程模型与 PHP 扩展，切换架构后需要重启服务。</p>
      </div>
      <UiButton size="small" @click="load">刷新</UiButton>
    </header>

    <UiCard v-if="loading">
      <UiSkeleton :rows="5" animated />
    </UiCard>

    <template v-else-if="data">
      <UiAlert v-if="data.current.degraded" type="warn" :closable="false" title="已降级运行">
        <div class="alert-reason">{{ data.current.reason || '当前架构与配置不一致，已自动降级。' }}</div>
        <ul v-if="data.current.limitations?.length" class="limit-list">
          <li v-for="(l, i) in data.current.limitations" :key="i">{{ l }}</li>
        </ul>
      </UiAlert>

      <UiCard>
        <template #header>
          <div class="card-head-row">
            <span class="card-title">运行架构</span>
            <UiTag type="accent" size="small">{{ data.current.label }}</UiTag>
            <UiTag type="info" effect="plain" size="small">当前配置：{{ data.configured }}</UiTag>
            <div class="spacer" />
          </div>
        </template>

        <div class="arch-list" role="radiogroup" aria-label="运行架构">
          <button
            v-for="m in data.modes"
            :key="m.id"
            type="button"
            role="radio"
            class="arch-item"
            :class="{ active: data.configured === m.id }"
            :aria-checked="data.configured === m.id"
            :disabled="saving"
            @click="choose(m.id)"
          >
            <span class="arch-dot" :class="{ on: data.configured === m.id }" />
            <div class="arch-text">
              <div class="arch-label">{{ m.label }}</div>
              <div class="arch-desc">{{ m.description ?? '' }}</div>
            </div>
            <UiTag v-if="data.current.mode === m.id" type="ok" size="small">运行中</UiTag>
            <UiTag v-else-if="data.configured === m.id" type="warn" size="small">待重启</UiTag>
          </button>
        </div>

        <div class="field-hint arch-hint">切换架构不会立即生效——进程模型在启动时确定，需要重启服务。</div>
      </UiCard>

      <UiCard padding="none">
        <template #header>
          <div class="card-head-row">
            <span class="card-title">PHP 扩展</span>
            <div class="spacer" />
            <UiTag type="info" effect="plain" size="small">PHP {{ data.php.version }}</UiTag>
          </div>
        </template>
        <UiTable :data="extensions" :columns="columns" row-key="name">
          <template #cell-label="{ row }">
            <span class="mono ext-name">{{ row.label }}</span>
          </template>
          <template #cell-present="{ row }">
            <UiTag :type="row.present ? 'ok' : 'info'" size="small">
              {{ row.present ? '已安装' : '未安装' }}
            </UiTag>
          </template>
        </UiTable>
      </UiCard>
    </template>

    <UiCard v-else>
      <UiEmpty description="没有取到运行时信息">
        <UiButton type="primary" @click="load">重试</UiButton>
      </UiEmpty>
    </UiCard>
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

.alert-reason { font-size: 12.5px; color: var(--text-dim); }
.limit-list {
  margin: 6px 0 0;
  padding-left: 18px;
  font-size: 12.5px;
  color: var(--text-dim);
  line-height: 1.8;
}

.arch-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
}
.arch-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 11px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: transparent;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 160ms ease, background 160ms ease;
}
.arch-item:hover:not(:disabled) { border-color: var(--border-strong); background: var(--bg-hover); }
.arch-item.active { border-color: var(--accent); background: var(--accent-soft); }
.arch-item:disabled { opacity: 0.6; cursor: not-allowed; }
.arch-dot {
  width: 14px;
  height: 14px;
  flex: none;
  border-radius: 50%;
  border: 1px solid var(--border-strong);
}
.arch-dot.on {
  border-color: var(--accent);
  background: radial-gradient(circle, var(--accent) 0 4px, transparent 4.5px);
}
.arch-text { flex: 1; min-width: 0; }
.arch-label { font-size: 13px; font-weight: 600; color: var(--text); }
.arch-desc { font-size: 11.5px; color: var(--text-faint); margin-top: 2px; }
.arch-hint { margin-top: 10px; }

.ext-name { font-size: 12.5px; color: var(--text-dim); }
</style>
