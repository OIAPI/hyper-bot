<script setup lang="ts">
/**
 * 插件一览 + 两种导入方式。
 *
 * 插件从 app/Plugins 目录与 runtime/github 下的包目录自动扫描，钩子由注解声明；
 * 这页原本只读，现在多了「导入插件」：上传 zip 包，或从 git 仓库直接克隆。
 *
 * 两条链路的后端语义是一致的：包落到 runtime/github/<name>，校验 composer.json
 * 的 autoload.psr-4，再让注册表重扫一遍。所以导入成功后这里直接刷新列表，
 * 不必让用户再点「重新加载」。
 */
import { computed, onMounted, ref } from 'vue'
import { Refresh, Upload, Link } from '@element-plus/icons-vue'
import { pluginApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import type { Plugin, PluginHook, PluginImportResult } from '@/types/api'
import type { UiColumn } from '@/ui/types'

const toast = useToastStore()
const auth = useAuthStore()

/** 表格插槽给到的行是宽松类型，这里收敛成具体类型 */
function asHook(row: unknown): PluginHook {
  return row as PluginHook
}

const items = ref<Plugin[]>([])
const loading = ref(true)
const reloading = ref(false)
const current = ref<Plugin | null>(null)
const visible = ref(false)
const count = computed(() => items.value.length)

const hookColumns: UiColumn[] = [
  { key: 'type', title: '类型', width: 100 },
  { key: 'method', title: '方法', minWidth: 180 },
  { key: 'match', title: '匹配', minWidth: 140 },
]

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await pluginApi.list()
    items.value = res.items ?? []
  } catch (e) {
    toast.fromError(e, '加载插件失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function reload(): Promise<void> {
  reloading.value = true
  try {
    await pluginApi.reload()
    toast.ok('插件已重新加载')
    await load()
  } catch (e) {
    toast.fromError(e, '重载失败')
  } finally {
    reloading.value = false
  }
}

function open(p: Plugin): void {
  current.value = p
  visible.value = true
}

/* ---------------- 导入 ---------------- */

type ImportMode = 'zip' | 'git'

const modeOptions = [
  { label: '上传 zip', value: 'zip' },
  { label: 'Git 仓库', value: 'git' },
]

const importVisible = ref(false)
const mode = ref<ImportMode>('zip')
const importing = ref(false)
const result = ref<PluginImportResult | null>(null)

// zip 分支
const fileEl = ref<HTMLInputElement | null>(null)
const picked = ref<File | null>(null)
const dragOver = ref(false)

// git 分支
const gitUrl = ref('')
const gitBranch = ref('')
const gitName = ref('')

const pickedSize = computed(() => {
  const f = picked.value
  if (!f) return ''
  const mb = f.size / 1024 / 1024
  return mb >= 1 ? mb.toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB'
})

const canSubmit = computed(() => {
  if (mode.value === 'zip') return !!picked.value
  return /^(https?:\/\/|git@|ssh:\/\/|git:\/\/)/i.test(gitUrl.value.trim())
})

function openImport(): void {
  mode.value = 'zip'
  result.value = null
  picked.value = null
  gitUrl.value = ''
  gitBranch.value = ''
  gitName.value = ''
  importVisible.value = true
}

function chooseFile(): void {
  fileEl.value?.click()
}

function takeFile(f: File | null | undefined): void {
  if (!f) return
  if (!/\.zip$/i.test(f.name)) {
    toast.warn('请选择 .zip 压缩包')
    return
  }
  picked.value = f
  result.value = null
}

function onFileChange(e: Event): void {
  const input = e.target as HTMLInputElement
  takeFile(input.files?.[0])
  // 允许再次选同一个文件也要触发 change
  input.value = ''
}

function onDrop(e: DragEvent): void {
  dragOver.value = false
  takeFile(e.dataTransfer?.files?.[0])
}

async function submit(): Promise<void> {
  if (!canSubmit.value || importing.value) return
  importing.value = true
  try {
    let res: PluginImportResult
    if (mode.value === 'zip' && picked.value) {
      res = await pluginApi.importZip(picked.value)
    } else {
      res = await pluginApi.importGit({
        url: gitUrl.value.trim(),
        branch: gitBranch.value.trim() || undefined,
        name: gitName.value.trim() || undefined,
      })
    }
    result.value = { ...res, warnings: res.warnings ?? [] }
    const w = res.warnings?.length ?? 0
    if (res.plugin) {
      toast.ok(w ? `已导入 ${res.name}（${w} 条提示）` : `已导入 ${res.name}`)
    } else {
      toast.warn(`已解包到 ${res.name}，但注册表没扫到插件类`)
    }
    await load()
  } catch (e) {
    toast.fromError(e, mode.value === 'zip' ? '导入失败' : '拉取仓库失败')
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>插件</h1>
        <p class="sub">从 app/Plugins 与 runtime/github 自动扫描；钩子通过注解声明。</p>
      </div>
      <div class="row gap-8">
        <UiTag size="small" type="plain">{{ count }} 个</UiTag>
        <UiButton v-if="auth.can('plugin.install')" size="small" @click="openImport">
          <template #icon><UiIcon><Upload /></UiIcon></template>
          导入插件
        </UiButton>
        <UiButton v-if="auth.can('plugin.reload')" size="small" :loading="reloading" @click="reload">
          <template #icon><UiIcon><Refresh /></UiIcon></template>
          {{ reloading ? '重载中…' : '重新加载' }}
        </UiButton>
      </div>
    </header>

    <UiCard v-if="loading">
      <UiSkeleton :rows="4" animated />
    </UiCard>

    <div v-else-if="items.length" class="plugin-grid">
      <UiCard v-for="p in items" :key="p.class" class="plugin-card">
        <div class="plugin-body">
          <div class="plugin-head">
            <div class="plugin-text">
              <div class="plugin-name">{{ p.name }}</div>
              <div class="plugin-short">{{ p.short }}</div>
            </div>
            <div class="spacer" />
            <span class="v-label">v{{ p.version || '—' }}</span>
          </div>

          <p class="plugin-desc">{{ p.description || '（无描述）' }}</p>

          <div class="plugin-stats">
            <span>{{ p.hooks.length }} 个钩子</span>
            <span>{{ p.methods }} 个方法</span>
            <span v-if="p.author">{{ p.author }}</span>
          </div>

          <div class="plugin-foot">
            <UiButton size="small" type="text" @click="open(p)">查看钩子</UiButton>
          </div>
        </div>
      </UiCard>
    </div>

    <UiCard v-else>
      <UiEmpty description="没有找到任何插件" />
    </UiCard>

    <UiDialog v-model="visible" :title="current?.name ?? ''" width="560px">
      <UiTable
        v-if="current"
        :data="current.hooks"
        :columns="hookColumns"
        size="small"
        empty-text="该插件没有声明任何钩子"
        max-height="360"
      >
        <template #cell-type="{ row }">
          <UiTag size="small" type="info">{{ asHook(row).type }}</UiTag>
        </template>
        <template #cell-method="{ row }">
          <span class="mono">{{ asHook(row).method }}</span>
        </template>
        <template #cell-match="{ row }">
          <span class="mono tiny">{{ asHook(row).match ?? '—' }}</span>
        </template>
      </UiTable>
    </UiDialog>

    <UiDialog v-model="importVisible" title="导入插件" width="560px">
      <div class="import">
        <div class="row between">
          <UiSegmented v-model="mode" :options="modeOptions" />
          <UiTag size="small" type="plain">装到 runtime/github</UiTag>
        </div>

        <!-- 结果回执 -->
        <UiAlert
          v-if="result"
          :type="result.plugin ? 'ok' : 'warn'"
          :title="result.plugin ? '已安装 ' + result.name : '已解包，但未识别为插件'"
          :description="
            result.path +
            ' · ' +
            result.files +
            ' 个文件' +
            (result.warnings?.length ? ' · ' + result.warnings.join('；') : '')
          "
        />

        <template v-if="mode === 'zip'">
          <div
            class="drop"
            :class="{ over: dragOver, filled: !!picked }"
            @click="chooseFile"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
          >
            <UiIcon class="drop-icon"><Upload /></UiIcon>
            <template v-if="picked">
              <div class="drop-name mono">{{ picked.name }}</div>
              <div class="drop-hint">{{ pickedSize }} · 点击重新选择</div>
            </template>
            <template v-else>
              <div class="drop-name">把 zip 拖到这里，或点击选择</div>
              <div class="drop-hint">只接受 .zip；包根目录需有 composer.json</div>
            </template>
          </div>
          <input
            ref="fileEl"
            type="file"
            accept=".zip,application/zip"
            class="hidden-input"
            @change="onFileChange"
          />
        </template>

        <template v-else>
          <UiForm label-position="top" class="git-form">
            <UiFormItem label="仓库地址">
              <UiInput
                v-model="gitUrl"
                placeholder="https://github.com/user/dsh-plugin.git"
                clearable
              />
            </UiFormItem>
            <div class="row gap-8">
              <UiFormItem label="分支（可空）" class="grow">
                <UiInput v-model="gitBranch" placeholder="默认用仓库 HEAD" />
              </UiFormItem>
              <UiFormItem label="目录名（可空）" class="grow">
                <UiInput v-model="gitName" placeholder="默认取仓库名" />
              </UiFormItem>
            </div>
          </UiForm>
        </template>

        <div class="import-note">
          <UiIcon class="note-icon"><Link /></UiIcon>
          <span>
            插件包需要 composer.json 声明 <code>autoload.psr-4</code>，入口类名与文件名同名且放在
            psr-4 根目录下；导入完成后自动重扫，无需重启。
          </span>
        </div>
      </div>

      <template #footer>
        <div class="row gap-8 end">
          <UiButton @click="importVisible = false">{{ result ? '完成' : '取消' }}</UiButton>
          <UiButton
            v-if="!result"
            type="primary"
            :loading="importing"
            :disabled="!canSubmit"
            @click="submit"
          >
            {{ importing ? (mode === 'zip' ? '上传中…' : '拉取中…') : '开始导入' }}
          </UiButton>
          <UiButton v-else type="primary" :disabled="!canSubmit" @click="result = null">
            再导一个
          </UiButton>
        </div>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
h1 { margin: 0; font-size: 17px; font-weight: 660; letter-spacing: -0.015em; }
p.sub { margin: 3px 0 0; font-size: 12.5px; color: var(--text-faint); }

.plugin-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
}
.plugin-card { height: 100%; }
.plugin-body { display: flex; flex-direction: column; gap: 10px; min-width: 0; }

.plugin-head { display: flex; align-items: center; gap: 8px; }
.plugin-text { min-width: 0; }
.plugin-name { font-size: 13px; font-weight: 620; letter-spacing: -0.01em; color: var(--text); }
.plugin-short { font-size: 11.5px; color: var(--text-faint); font-family: var(--font-mono); }

.plugin-desc { margin: 0; font-size: 12.5px; color: var(--text-dim); line-height: 1.65; }
.plugin-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 11.5px;
  color: var(--text-faint);
  font-variant-numeric: tabular-nums;
}
.plugin-foot { margin-top: 2px; }

.v-label {
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--text-faint);
}

.mono { font-family: var(--font-mono); }
.tiny { font-size: 11.5px; }

/* ---- 导入对话框 ---- */
.import { display: flex; flex-direction: column; gap: 14px; }

.hidden-input { display: none; }

.drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 118px;
  padding: 18px;
  border: 1px dashed var(--border, rgba(0, 0, 0, 0.14));
  border-radius: 10px;
  background: var(--surface-2, rgba(0, 0, 0, 0.02));
  cursor: pointer;
  transition: border-color 0.16s, background 0.16s;
  text-align: center;
}
.drop:hover { border-color: var(--accent, #6b7f52); }
.drop.over { border-color: var(--accent, #6b7f52); background: var(--surface-3, rgba(0, 0, 0, 0.04)); }
.drop.filled { border-style: solid; }

.drop-icon { font-size: 20px; color: var(--text-faint); }
.drop-name { font-size: 12.5px; color: var(--text); word-break: break-all; }
.drop-hint { font-size: 11.5px; color: var(--text-faint); }

.git-form { display: block; }
.grow { flex: 1; min-width: 0; }

.import-note {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  font-size: 11.5px;
  line-height: 1.7;
  color: var(--text-faint);
}
.note-icon { margin-top: 3px; flex: none; }
.import-note code {
  font-family: var(--font-mono);
  font-size: 11px;
  padding: 1px 4px;
  border-radius: 4px;
  background: var(--surface-3, rgba(0, 0, 0, 0.05));
}

.end { justify-content: flex-end; }
</style>
