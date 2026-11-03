<script setup lang="ts">
/**
 * 程序更新：git 仓库 / 压缩包。
 *
 * 只覆盖程序文件：runtime/、vendor/、.git/、Config.ini 永远不会被动，
 * 被覆盖的旧文件先备份到 runtime/update/backup-<时间>/。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { updateApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { UpdateCheckResult, UpdateInfo, UpdateResult } from '@/types/api'

const auth = useAuthStore()
const toast = useToastStore()

const info = ref<UpdateInfo | null>(null)
const loading = ref(false)
const isOwner = computed(() => auth.isOwner || auth.role === 'owner')

const mode = ref<'git' | 'zip' | 'builtin'>('builtin')

const branch = ref('')
const repoUrl = ref('')
const proxy = ref('')
const proxyOptions = computed(() => info.value?.proxies ?? [{ label: '直连', prefix: '' }])
// 必须缓存：每次渲染都新建数组会让下拉选项被重建，手机上表现为「点一下就闪没了」
const proxySelectOptions = computed(() =>
  proxyOptions.value.map((p) => ({ label: p.label, value: p.prefix })),
)

const checking = ref(false)
const checkResult = ref<UpdateCheckResult | null>(null)
const running = ref(false)
const result = ref<UpdateResult | null>(null)
const error = ref('')
const file = ref<File | null>(null)
const confirming = ref(false)

watch(info, (v) => {
  if (!v) return
  if (!repoUrl.value && v.git.remote) repoUrl.value = v.git.remote
  if (!branch.value && v.git.branch) branch.value = v.git.branch
})

async function load(): Promise<void> {
  loading.value = true
  try {
    info.value = await updateApi.info()
  } catch (e) {
    toast.fromError(e, '读取版本信息失败')
  } finally {
    loading.value = false
  }
}

async function doCheck(): Promise<void> {
  checking.value = true
  checkResult.value = null
  error.value = ''
  try {
    checkResult.value = await updateApi.check()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '检查失败'
  } finally {
    checking.value = false
  }
}

function pickFile(e: Event): void {
  const target = e.target as HTMLInputElement
  file.value = target.files && target.files[0] ? target.files[0] : null
  error.value = ''
}

function askRun(): void {
  error.value = ''
  if (mode.value === 'git' && !repoUrl.value.trim()) {
    error.value = '请填写仓库地址'
    return
  }
  if (mode.value === 'zip' && !file.value) {
    error.value = '请选择压缩包'
    return
  }
  confirming.value = true
}

async function run(): Promise<void> {
  confirming.value = false
  running.value = true
  error.value = ''
  result.value = null
  try {
    if (mode.value === 'zip' && file.value) {
      result.value = await updateApi.fromZip(file.value)
    } else {
      result.value = await updateApi.fromGit({
        url: repoUrl.value.trim(),
        branch: branch.value.trim(),
        proxy: proxy.value,
      })
    }
    toast.ok(result.value.msg)
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '更新失败'
  } finally {
    running.value = false
  }
}

const gitText = computed(() => {
  const g = info.value?.git
  if (!g) return '—'
  if (!g.available) return '没有 git 命令，只能用压缩包更新'
  if (!g.repo) return '当前目录不是 git 仓库，只能用压缩包或 git 链接更新'
  return g.commit + (g.subject ? ' · ' + g.subject : '')
})

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>程序更新</h1>
        <p class="sub">
          只覆盖程序文件：<b>runtime/、vendor/、.git/、Config.ini 不会被改动</b>；
          被覆盖的旧文件先备份到 <code>runtime/update/backup-&lt;时间&gt;/</code>
        </p>
      </div>
      <UiButton size="small" :loading="loading" @click="load">刷新</UiButton>
    </header>

    <UiAlert v-if="!isOwner" type="warning" :closable="false">
      只有主人可以执行更新（更新会覆盖写接口与鉴权代码）。
    </UiAlert>

    <div class="stat-row">
      <div class="stat"><div class="stat-value">v{{ info?.version || '—' }}</div><div class="stat-label">当前版本</div></div>
      <div class="stat wide"><div class="stat-value small mono">{{ gitText }}</div><div class="stat-label">git 状态（{{ info?.git.branch || '无分支' }}）</div></div>
      <div class="stat wide">
        <div class="stat-value small">
          <template v-if="info?.last">
            {{ info.last.changed }} 个文件 · {{ info.last.version }} · {{ info.last.at }}
          </template>
          <template v-else>还没有更新记录</template>
        </div>
        <div class="stat-label">上次更新</div>
      </div>
    </div>

    <UiCard>
      <template #header><span class="card-title">更新方式</span></template>
      <div class="modes">
        <button type="button" class="mode" :class="{ on: mode === 'builtin' }" @click="mode = 'builtin'">
          <span class="mode-name">检查更新</span>
          <span class="mode-hint">git fetch 对比远端，只报告不落盘</span>
        </button>
        <button type="button" class="mode" :class="{ on: mode === 'git' }" @click="mode = 'git'">
          <span class="mode-name">从 Git 链接更新</span>
          <span class="mode-hint">支持 gh-proxy.com 等加速前缀</span>
        </button>
        <button type="button" class="mode" :class="{ on: mode === 'zip' }" @click="mode = 'zip'">
          <span class="mode-name">从压缩包更新</span>
          <span class="mode-hint">发布包 zip，自动剥掉外层目录</span>
        </button>
      </div>

      <div v-if="mode === 'builtin'" class="pane">
        <div class="field-hint">
          需要当前目录是 git 仓库且配置了远端（<code>git remote add origin …</code>）。
          现在检查：<b>{{ info?.git.repo ? '可用' : '不可用' }}</b>
        </div>
        <div class="row">
          <UiButton size="small" :loading="checking" @click="doCheck">检查远端</UiButton>
        </div>
        <div v-if="checkResult" class="check-result">
          <div>{{ checkResult.msg }}</div>
          <div v-if="checkResult.latest" class="tiny">远端最新：{{ checkResult.latest }}</div>
          <div v-if="checkResult.current" class="tiny">当前：{{ checkResult.current }}</div>
        </div>
      </div>

      <div v-else-if="mode === 'git'" class="pane">
        <div class="grid">
          <label class="field span-2">
            <span class="field-label">仓库地址</span>
            <UiInput v-model="repoUrl" placeholder="https://github.com/user/hyper-bot.git" />
          </label>
          <label class="field">
            <span class="field-label">分支</span>
            <UiInput v-model="branch" placeholder="留空用默认分支" />
          </label>
          <label class="field">
            <span class="field-label">加速前缀</span>
            <UiSelect
              :model-value="proxy"
              :options="proxySelectOptions"
              @update:model-value="(v: string | number) => (proxy = String(v))"
            />
          </label>
        </div>
        <div class="field-hint">
          用的是 <code>git clone --depth 1</code> 到临时目录再覆盖，不碰现有 .git 历史，也不产生合并冲突。
        </div>
      </div>

      <div v-else class="pane">
        <div class="grid">
          <label class="field span-2">
            <span class="field-label">发布包（zip，最大 {{ info?.limits.zip_mb || 64 }} MB）</span>
            <input class="file-input" type="file" accept=".zip" @change="pickFile" />
          </label>
        </div>
        <div class="field-hint">
          包内需要有 <code>composer.json</code>；多一层目录（如 <code>hyper-bot-v2.3.0/</code>）会自动识别。
        </div>
      </div>

      <div v-if="error" class="form-error">{{ error }}</div>

      <div class="row">
        <UiButton v-if="mode !== 'builtin'" size="small" type="primary" :disabled="!isOwner" :loading="running" @click="askRun">
          {{ running ? '更新中…' : '开始更新' }}
        </UiButton>
      </div>
    </UiCard>

    <UiCard v-if="result">
      <template #header><span class="card-title">更新结果</span></template>
      <div class="result">
        <div>{{ result.msg }}</div>
        <div class="tiny">
          版本 {{ result.version || '—' }} · 变更 {{ result.changed }} 个文件 · 跳过 {{ result.skipped ?? 0 }} 个受保护路径
        </div>
        <div v-if="result.backup" class="tiny mono">备份：{{ result.backup }}</div>
        <ul v-if="result.warnings?.length" class="warns">
          <li v-for="w in result.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>
    </UiCard>

    <UiDialog v-model="confirming" title="确认更新" width="460px">
      <div class="confirm">
        <p>即将覆盖当前程序文件，<b>runtime/、vendor/、Config.ini 会保留</b>，被覆盖的旧文件会先备份。</p>
        <p>更新过程中请勿关闭页面；完成后如有依赖变化，需要执行 <code>php bin/hyper install --force</code>。</p>
      </div>
      <template #footer>
        <UiButton size="small" @click="confirming = false">取消</UiButton>
        <UiButton size="small" type="primary" @click="run">确认更新</UiButton>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.page-head h1 { margin: 0; font-size: 19px; }
.sub { margin: 4px 0 0; font-size: 12px; color: var(--text-faint); line-height: 1.7; }
.sub code, .field-hint code { font-family: var(--font-mono); font-size: 11.5px; }

.stat-row { display: grid; grid-template-columns: 1fr 2fr 2fr; gap: 10px; }
.stat { padding: 12px 14px; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); background: var(--bg-card); }
.stat-value { font-size: 20px; font-weight: 600; }
.stat-value.small { font-size: 12px; font-weight: 500; }
.mono { font-family: var(--font-mono); }
.stat-label { margin-top: 2px; font-size: 11px; color: var(--text-faint); }

.modes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.mode { display: flex; flex-direction: column; gap: 3px; padding: 10px 12px; text-align: left; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); background: transparent; cursor: pointer; }
.mode.on { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, transparent); }
.mode-name { font-size: 12.5px; color: var(--text); }
.mode-hint { font-size: 11px; color: var(--text-faint); }

.pane { display: flex; flex-direction: column; gap: 10px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field.span-2 { grid-column: span 2; }
.field-label { font-size: 11.5px; color: var(--text-dim); }
.field-hint { font-size: 11.5px; color: var(--text-faint); line-height: 1.7; }
.row { display: flex; gap: 8px; }
.file-input { font-size: 12px; }
.check-result { font-size: 12px; color: var(--text-dim); display: flex; flex-direction: column; gap: 4px; }
.tiny { font-size: 11px; color: var(--text-faint); }
.result { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; }
.warns { margin: 6px 0 0; padding-left: 18px; font-size: 11.5px; color: var(--warning, #b8860b); line-height: 1.8; }
.confirm p { margin: 0 0 8px; font-size: 12.5px; line-height: 1.8; color: var(--text-dim); }
.confirm code { font-family: var(--font-mono); font-size: 11.5px; }
.form-error {
  margin-top: 10px; padding: 7px 10px; border-radius: var(--radius-sm);
  border: 1px solid var(--danger, #d33); color: var(--danger, #d33);
  background: color-mix(in srgb, var(--danger, #d33) 8%, transparent);
  font-size: 12px; line-height: 1.6; user-select: text;
}
</style>