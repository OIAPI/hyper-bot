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
import { computed, onMounted, ref } from 'vue'
import { Search, Plus } from '@element-plus/icons-vue'
import { groupApi, pluginApi } from '@/api'
import { useToastStore } from '@/stores/toast'
import { formatTime } from '@/utils/format'
import type { Group, Plugin } from '@/types/api'
import type { UiColumn } from '@/ui/types'

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

const manage = ref<Group | null>(null)
const pluginBusy = ref<string | null>(null)

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
      groupApi.list(query.value.trim() || undefined),
      pluginApi.list(),
    ])
    items.value = groupRes.items ?? []
    plugins.value = pluginRes.items ?? []
  } catch (e) {
    toast.fromError(e, '加载群列表失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function applyUpdate(g: Group): void {
  const i = items.value.findIndex((x) => x.group_id === g.group_id)
  if (i >= 0) items.value[i] = g
  if (manage.value && manage.value.group_id === g.group_id) manage.value = g
}

async function toggleStatus(g: Group): Promise<void> {
  busy.value = true
  try {
    const updated = await groupApi.toggleStatus(g.group_id)
    applyUpdate(updated)
    toast.ok(updated.status ? '已开启该群' : '已关闭该群')
  } catch (e) {
    toast.fromError(e, '切换失败')
  } finally {
    busy.value = false
  }
}

async function togglePlugin(g: Group, p: Plugin): Promise<void> {
  pluginBusy.value = p.class
  try {
    const updated = await groupApi.togglePlugin(g.group_id, p.class)
    applyUpdate(updated)
  } catch (e) {
    toast.fromError(e, '切换插件失败')
  } finally {
    pluginBusy.value = null
  }
}

/** 没显式关掉就是开着的 */
function pluginOn(g: Group, p: Plugin): boolean {
  return g.worker[p.class] !== false
}

async function submitAdd(): Promise<void> {
  if (!addGroupId.value.trim()) {
    toast.warn('请填写群号')
    return
  }
  adding.value = true
  try {
    await groupApi.create({ group_id: addGroupId.value.trim(), name: addName.value.trim() })
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
    await groupApi.destroy(g.group_id)
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
        <p class="sub">机器人收到群消息后会自动纳管；状态与插件开关即时热重载。</p>
      </div>
      <div class="row gap-8">
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
        <UiTable v-if="items.length" :data="items" :columns="columns" row-key="group_id">
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
            <UiButton size="small" type="text" @click="manage = asGroup(row)">管理</UiButton>
          </template>
        </UiTable>

        <UiEmpty
          v-else
          description="还没有纳管任何群。机器人收到群消息后会自动出现在这里，也可以手动添加。"
        />
      </template>
    </UiCard>

    <UiDialog v-model="manageOpen" :title="'群 ' + (manage?.group_id ?? '')" width="560px">
      <div v-if="manage" class="manage">
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
              <div class="plugin-desc">{{ p.description || '（无描述）' }}</div>
            </div>
            <UiSwitch
              :model-value="pluginOn(manage, p)"
              :loading="pluginBusy === p.class"
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
.mono { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
.tiny { font-size: 11.5px; }
.dim { color: var(--text-faint); }

.manage { display: flex; flex-direction: column; gap: 10px; }
.row-between { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.plugin-list { display: flex; flex-direction: column; gap: 12px; }
.plugin-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.plugin-text { min-width: 0; }
.plugin-name { font-size: 12.8px; color: var(--text); }
.plugin-desc { font-size: 11.5px; color: var(--text-faint); }

.dialog-foot { display: flex; align-items: center; gap: 8px; }
</style>