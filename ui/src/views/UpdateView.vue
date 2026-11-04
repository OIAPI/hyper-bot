<script setup lang="ts">
/**
 * 程序更新：git 仓库 / 压缩包 / 直链。
 *
 * 只覆盖程序文件：runtime/、vendor/、.git/、Config.ini 永远不会被动，
 * 被覆盖的旧文件先备份到 runtime/update/backup-<时间>/。
 *
 * v3.2 起「接收包」和「应用包」分开：
 *  - 压缩包按 512 KB 分片上传（低于 nginx 默认 1m 请求体上限），
 *    合并后立即返回 job，解压/覆盖在后台命令行进程里跑；
 *  - 页面只轮询 /update/job，所以反代不会再因为解压几十秒
 *    先超时断连（readv() failed 104 / 403）。
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { updateApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { UpdateCheckResult, UpdateInfo, UpdateJob } from '@/types/api'

const auth = useAuthStore()
const toast = useToastStore()

const info = ref<UpdateInfo | null>(null)
const loading = ref(false)
const isOwner = computed(() => auth.isOwner || auth.role === 'owner')

const mode = ref<'builtin' | 'git' | 'zip' | 'url'>('builtin')

const branch = ref('')
const repoUrl = ref('')
const proxy = ref('')
const proxyOptions = computed(() => info.value?.proxies ?? [{ label: '直连', prefix: '' }])
// 必须缓存：每次渲染都新建数组会让下拉选项被重建，手机上表现为「点一下就闪没了」
const proxySelectOptions = computed(() =>
  proxyOptions.value.map((p) => ({ label: p.label, value: p.prefix })),
)

const attachUrl = ref('')
const attachBranch = ref('')
const attaching = ref(false)
const attachMsg = ref('')

async function doAttach(): Promise<void> {
  attachMsg.value = ''
  if (!attachUrl.value.trim()) { attachMsg.value = '请填写仓库地址'; return }
  attaching.value = true
  try {
    const res = await updateApi.attach({ url: attachUrl.value.trim(), branch: attachBranch.value.trim(), proxy: proxy.value })
    attachMsg.value = res.msg
    toast.ok(res.msg)
    await load()
  } catch (e) {
    attachMsg.value = e instanceof Error ? e.message : '关联失败'
  } finally {
    attaching.value = false
  }
}

const checking = ref(false)
const checkResult = ref<UpdateCheckResult | null>(null)
const error = ref('')
const file = ref<File | null>(null)
const confirming = ref(false)

/** 从链接更新 */
const zipUrl = ref('')
const zipUrlProxy = ref('')

/** 上传 / 任务状态 */
const job = ref<UpdateJob | null>(null)
const uploading = ref(false)
const uploadPct = ref(0)
const uploadNote = ref('')
const busy = computed(() => uploading.value || job.value?.status === 'running' || job.value?.status === 'queued')

let pollTimer = 0

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
  uploadPct.value = 0
  uploadNote.value = ''
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
  if (mode.value === 'url' && !zipUrl.value.trim()) {
    error.value = '请填写压缩包链接'
    return
  }
  if (busy.value) {
    error.value = '已经有一个更新任务在跑，等它结束再试'
    return
  }
  confirming.value = true
}

function humanSize(bytes: number): string {
  if (!bytes) return '0 B'
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1024 / 1024).toFixed(2) + ' MB'
}

/** 轮询后台任务：直到 done / failed，或者页面卸载 */
function watchJob(initial?: UpdateJob | null): void {
  job.value = initial ?? null
  stopPoll()
  const tick = async (): Promise<void> => {
    try {
      const res = await updateApi.job()
      // 任务文件可能在应用更新后被清掉：别在这里解引用 null 或无限轮询
      if (!res.job) {
        stopPoll()
        return
      }
      job.value = res.job
      const status = res.job.status
      if (status === 'done' || status === 'failed' || status === 'stale') {
        stopPoll()
        if (status === 'done') {
          toast.ok('更新完成' + (res.job.result?.version ? '：v' + res.job.result.version : ''))
          await load()
        } else {
          toast.error('更新失败：' + (res.job.msg || '看日志'))
        }
        return
      }
      pollTimer = window.setTimeout(() => void tick(), 1500)
    } catch {
      // 后台进程应用更新时可能短暂重启：继续重试，别把任务判死
      pollTimer = window.setTimeout(() => void tick(), 2500)
    }
  }
  void tick()
}

function stopPoll(): void {
  if (pollTimer) window.clearTimeout(pollTimer)
  pollTimer = 0
}

/**
 * 分片上传：init 拿 chunk_size → 顺序发每一片 → finish 合并 + 起任务。
 * 顺序发送很重要：php -S 是单线程，并发只会互相排队还更难报错。
 */
async function uploadZip(picked: File): Promise<UpdateJob | null> {
  uploading.value = true
  uploadPct.value = 0
  error.value = ''
  uploadNote.value = '正在初始化…'
  try {
    const init = await updateApi.chunkInit({ filename: picked.name, size: picked.size })
    const size = init.chunk_size
    const total = init.total
    for (let i = 0; i < total; i += 1) {
      const start = i * size
      const blob = picked.slice(start, Math.min(start + size, picked.size))
      await updateApi.chunkUpload(init.upload, i, blob)
      uploadPct.value = Math.round(((i + 1) / total) * 100)
      uploadNote.value =
        '上传中 ' + (i + 1) + '/' + total + ' 片 · ' +
        humanSize(Math.min(start + size, picked.size)) + ' / ' + humanSize(picked.size)
    }
    uploadNote.value = '正在合并并启动后台更新…'
    const res = await updateApi.chunkFinish(init.upload)
    uploadNote.value = '已交给后台任务，页面可以继续用'
    return res.job
  } catch (e) {
    error.value = e instanceof Error ? e.message : '上传失败'
    return null
  } finally {
    uploading.value = false
  }
}

async function run(): Promise<void> {
  confirming.value = false
  error.value = ''
  try {
    if (mode.value === 'zip' && file.value) {
      const started = await uploadZip(file.value)
      if (started) watchJob(started)
      return
    }
    if (mode.value === 'url') {
      uploadNote.value = '服务器正在下载…'
      const res = await updateApi.fromUrl({ url: zipUrl.value.trim(), proxy: zipUrlProxy.value })
      watchJob(res.job)
      return
    }
    // git：服务端仍然同步拉取（仓库很小），完了直接刷新信息
    const res = await updateApi.fromGit({
      url: repoUrl.value.trim(),
      branch: branch.value.trim(),
      proxy: proxy.value,
    })
    toast.ok(res.msg)
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '更新失败'
  } finally {
    uploadNote.value = ''
  }
}

const gitText = computed(() => {
  const g = info.value?.git
  if (!g) return '—'
  if (!g.available) return '没有 git 命令，只能用压缩包更新'
  if (!g.repo) return '当前目录不是 git 仓库，只能用压缩包或 git 链接更新'
  return g.commit + (g.subject ? ' · ' + g.subject : '')
})

const jobLabel: Record<string, string> = {
  idle: '空闲',
  queued: '排队中',
  running: '更新中',
  done: '已完成',
  failed: '失败',
  stale: '卡住了',
}

onMounted(async () => {
  await load()
  // 刷新页面 / 换设备进来时，可能上一轮任务还在跑：接管它
  try {
    const res = await updateApi.job()
    if (res.job && (res.job.status === 'running' || res.job.status === 'queued')) {
      watchJob(res.job)
    } else if (res.job && res.job.status !== 'idle') {
      job.value = res.job
    }
  } catch {
    // 老版本后端没有 /update/job：忽略
  }
})

onUnmounted(stopPoll)
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
          <span class="mode-hint">服务端 clone --depth 1 再覆盖</span>
        </button>
        <button type="button" class="mode" :class="{ on: mode === 'zip' }" @click="mode = 'zip'">
          <span class="mode-name">从压缩包更新</span>
          <span class="mode-hint">分片上传，后台解压（推荐）</span>
        </button>
        <button type="button" class="mode" :class="{ on: mode === 'url' }" @click="mode = 'url'">
          <span class="mode-name">从直链更新</span>
          <span class="mode-hint">服务器自己下载，不经过浏览器</span>
        </button>
      </div>

      <div v-if="mode === 'builtin'" class="pane">
        <div class="field-hint">
          需要当前目录是 git 仓库且配置了远端（<code>git remote add origin …</code>）。
          现在检查：<b>{{ info?.git.repo ? '可用' : '不可用' }}</b>
        </div>
        <div class="row">
          <UiButton size="small" :loading="checking" :disabled="!info?.git.repo" @click="doCheck">检查远端</UiButton>
        </div>

        <div v-if="info && !info.git.repo" class="attach-box">
          <div class="field-hint">
            当前目录还不是 git 仓库。填地址关联一次（只写 .git，不覆盖任何文件），之后就能用「检查更新」：
          </div>
          <div class="grid">
            <label class="field span-2">
              <span class="field-label">仓库地址</span>
              <UiInput v-model="attachUrl" placeholder="https://github.com/OIAPI/hyper-bot.git" />
            </label>
            <label class="field">
              <span class="field-label">分支</span>
              <UiInput v-model="attachBranch" placeholder="留空用默认分支" />
            </label>
            <div class="field">
              <span class="field-label">&nbsp;</span>
              <UiButton size="small" :loading="attaching" @click="doAttach">关联现有目录</UiButton>
            </div>
          </div>
          <div v-if="attachMsg" class="tiny">{{ attachMsg }}</div>
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

      <div v-else-if="mode === 'url'" class="pane">
        <div class="grid">
          <label class="field span-2">
            <span class="field-label">压缩包直链（zip）</span>
            <UiInput v-model="zipUrl" placeholder="https://github.com/OIAPI/hyper-bot/archive/HEAD.zip" />
          </label>
          <label class="field">
            <span class="field-label">加速前缀</span>
            <UiSelect
              :model-value="zipUrlProxy"
              :options="proxySelectOptions"
              @update:model-value="(v: string | number) => (zipUrlProxy = String(v))"
            />
          </label>
        </div>
        <div class="field-hint">
          服务器直接下载，浏览器只发一个很小的请求。仓库主页地址会自动换成
          <code>/archive/HEAD.zip</code>；发布包直链也可以。
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
          按 512 KB 分片上传，超过反代默认 1 MB 请求体上限也不会 413；
          解压在后台进程里跑，页面不会因为等待而超时报 403/104。
        </div>
        <div v-if="uploading || uploadPct > 0" class="progress-box">
          <div class="progress-bar"><span :style="{ width: uploadPct + '%' }" /></div>
          <div class="tiny">{{ uploadPct }}% · {{ uploadNote }}</div>
        </div>
      </div>

      <div v-if="error" class="form-error">{{ error }}</div>

      <div class="row">
        <UiButton
          v-if="mode !== 'builtin'"
          size="small"
          type="primary"
          :disabled="!isOwner || busy"
          :loading="uploading"
          @click="askRun"
        >
          {{ uploading ? '上传中…' : busy ? '更新进行中…' : '开始更新' }}
        </UiButton>
      </div>
    </UiCard>

    <!-- 后台任务：状态 + 最近日志 -->
    <UiCard v-if="job && job.status !== 'idle'">
      <template #header>
        <div class="job-head">
          <span class="card-title">更新任务</span>
          <UiTag :type="job.status === 'done' ? 'ok' : job.status === 'failed' || job.status === 'stale' ? 'danger' : 'warn'" size="small" round>
            {{ jobLabel[job.status] ?? job.status }}
          </UiTag>
        </div>
      </template>
      <div class="result">
        <div>{{ job.msg || '等待后台进程…' }}</div>
        <div class="tiny">
          {{ job.source === 'url' ? '直链下载' : job.source === 'zip' ? '压缩包' : job.source }}
          <template v-if="job.file"> · {{ job.file }}</template>
          <template v-if="job.size"> · {{ humanSize(job.size) }}</template>
          <template v-if="job.age"> · 已跑 {{ job.age }} 秒</template>
        </div>
        <div v-if="job.result" class="tiny">
          版本 {{ job.result.version || '—' }} · 变更 {{ job.result.changed }} 个文件 · 跳过 {{ job.result.skipped ?? 0 }} 个受保护路径
        </div>
        <div v-if="job.result?.backup" class="tiny mono">备份：{{ job.result.backup }}</div>
        <ul v-if="job.result?.warnings?.length" class="warns">
          <li v-for="w in job.result.warnings" :key="w">{{ w }}</li>
        </ul>
        <div v-if="job.log?.length" class="job-log">
          <div v-for="(line, i) in job.log" :key="i" class="log-line">{{ line }}</div>
        </div>
      </div>
    </UiCard>

    <UiDialog v-model="confirming" title="确认更新" width="460px">
      <div class="confirm">
        <p>即将覆盖当前程序文件，<b>runtime/、vendor/、Config.ini 会保留</b>，被覆盖的旧文件会先备份。</p>
        <p v-if="mode === 'zip'">{{ file?.name }} · {{ humanSize(file?.size ?? 0) }}，分片上传后在后台解压。</p>
        <p>更新在后台进行，页面可以关掉；回来后进度还在。完成后如有依赖变化，需要执行 <code>php bin/hyper install --force</code>。</p>
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

.modes { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
@media (max-width: 860px) { .modes { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
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
.row { display: flex; gap: 8px; margin-top: 4px; }
.file-input { font-size: 12px; }
.attach-box { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border-soft); display: flex; flex-direction: column; gap: 8px; }
.check-result { font-size: 12px; color: var(--text-dim); display: flex; flex-direction: column; gap: 4px; }
.tiny { font-size: 11px; color: var(--text-faint); }
.job-head { display: flex; align-items: center; gap: 8px; }
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
.progress-box { display: flex; flex-direction: column; gap: 5px; }
.progress-bar { height: 6px; border-radius: 999px; background: var(--border-soft); overflow: hidden; }
.progress-bar span { display: block; height: 100%; background: var(--accent); transition: width 0.25s ease; }
.job-log {
  margin-top: 8px; max-height: 220px; overflow: auto; padding: 9px 11px;
  border-radius: var(--radius-sm); background: var(--bg-inset, rgba(127, 127, 127, 0.08));
  font-family: var(--font-mono); font-size: 11px; line-height: 1.7; color: var(--text-dim);
}
.log-line { white-space: pre-wrap; word-break: break-word; }
</style>
