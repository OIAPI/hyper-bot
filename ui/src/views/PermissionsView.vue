<script setup lang="ts">
/**
 * QQ 成员权限：机器人认人的等级，决定谁能用管理命令。
 *
 * 注意：这里的权限只影响机器人在群里的行为，和「账号设置」里能登录后台的账号
 * 完全是两套东西 —— 面板账号用用户名密码，这里用 QQ 号。
 */
import { computed, onMounted, ref } from 'vue'
import { permissionApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PermissionsData } from '@/types/api'
import type { UiColumn } from '@/ui/types'

const auth = useAuthStore()
const toast = useToastStore()

const data = ref<PermissionsData | null>(null)
const loading = ref(false)
const canWrite = computed(() => auth.can('settings.write'))

const columns: UiColumn[] = [
  { key: 'qq', title: 'QQ 号', width: 160 },
  { key: 'level', title: '权限等级', width: 200 },
  { key: 'label', title: '说明', minWidth: 160 },
  { key: 'ops', title: '操作', width: 100, align: 'right' },
]

const rows = computed(() => data.value?.items ?? [])
const levels = computed(() => {
  // 后端把角色放在 levels 里（面板下拉用），角色名就是存储值
  const map = (data.value as any)?.model?.roles ?? data.value?.levels ?? {}
  return Object.entries(map).map(([value, label]) => ({ value, label: String(label) }))
})

const newQq = ref('')
const newLevel = ref('admin')
const matrix = computed(() => {
  const m = (data.value as any)?.model
  if (!m?.matrix) return [] as Array<{ role: string; label: string; level: number; capabilities: string[] }>
  return Object.entries(m.matrix).map(([role, meta]: any) => ({
    role,
    label: meta.label,
    level: meta.level,
    capabilities: meta.capabilities ?? [],
  }))
})
const capLabel = computed(() => ((data.value as any)?.model?.capabilities ?? {}) as Record<string, string>)
const adding = ref(false)
const addError = ref('')

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await permissionApi.list()
  } catch (e) {
    toast.fromError(e, '加载权限失败')
  } finally {
    loading.value = false
  }
}

async function add(): Promise<void> {
  addError.value = ''
  if (!/^\d{5,12}$/.test(newQq.value.trim())) {
    addError.value = 'QQ 号必须是 5-12 位数字'
    return
  }
  adding.value = true
  try {
    data.value = await permissionApi.save(newQq.value.trim(), newLevel.value)
    toast.ok('已设置 ' + newQq.value.trim())
    newQq.value = ''
  } catch (e) {
    addError.value = e instanceof Error ? e.message : '设置失败'
  } finally {
    adding.value = false
  }
}

async function change(row: { qq: string; level: string }, level: string): Promise<void> {
  try {
    const res = await permissionApi.save(row.qq, level)
    data.value = res
    toast.ok(row.qq + ' → ' + (levels.value.find((l) => l.value === level)?.label ?? level))
  } catch (e) {
    toast.fromError(e, '修改失败')
    await load()
  }
}

async function remove(row: { qq: string }): Promise<void> {
  try {
    const res = await permissionApi.remove(row.qq)
    data.value = res
    toast.ok('已移除 ' + row.qq)
  } catch (e) {
    toast.fromError(e, '移除失败')
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>QQ 成员权限</h1>
        <p class="sub">
          控制<strong>谁能在群里使用机器人的管理命令</strong>。这是机器人使用权限，
          与后台登录账号无关；不在列表里的人按群角色（群主 / 管理员）判定。
        </p>
      </div>
      <UiButton size="small" :loading="loading" @click="load">刷新</UiButton>
    </header>

    <UiAlert type="info" :closable="false">
      面板登录账号（用户名 / 密码 / 第三方登录）在「账号设置」里管理，和这里的 QQ 号是两套体系。
    </UiAlert>

    <div class="stat-row">
      <div class="stat"><div class="stat-value">{{ data?.stats.total ?? 0 }}</div><div class="stat-label">已单独授权</div></div>
      <div class="stat wide">
        <div class="stat-value small">
          <span v-for="(count, level) in (data?.stats.by_level ?? {})" :key="level" class="chip">
            {{ data?.levels[level] || level }} · {{ count }}
          </span>
        </div>
        <div class="stat-label">等级分布</div>
      </div>
    </div>

    <UiCard v-if="canWrite">
      <template #header><span class="card-title">添加 / 修改</span></template>
      <div class="add-row">
        <UiInput v-model="newQq" class="qq-input" placeholder="QQ 号，如 3889012345" />
        <UiSelect v-model="newLevel" :options="levels" class="level-select" />
        <UiButton size="small" type="primary" :loading="adding" @click="add">保存</UiButton>
      </div>
      <div v-if="addError" class="form-error">{{ addError }}</div>
    </UiCard>

    <UiCard :padding="'none'">
      <UiTable :data="rows" :columns="columns" row-key="qq" :loading="loading" empty-text="还没有单独授权的成员">
        <template #cell-qq="{ row }">
          <span class="mono">{{ (row as any).qq }}</span>
        </template>
        <template #cell-level="{ row }">
          <UiSelect
            :model-value="(row as any).level"
            :options="levels"
            size="small"
            :disabled="!canWrite"
            @update:model-value="(v: any) => change(row as any, String(v))"
          />
        </template>
        <template #cell-label="{ row }">
          <span class="dim">{{ (row as any).label }}</span>
        </template>
        <template #cell-ops="{ row }">
          <UiButton v-if="canWrite" size="small" text type="danger" @click="remove(row as any)">移除</UiButton>
        </template>
      </UiTable>
    </UiCard>

    <UiCard v-if="matrix.length">
      <template #header><span class="card-title">角色能力</span></template>
      <div class="matrix">
        <div v-for="r in matrix" :key="r.role" class="matrix-row">
          <div class="matrix-role">{{ r.label }}<span class="matrix-level">{{ r.level }}</span></div>
          <div class="matrix-caps">
            <span v-for="cap in r.capabilities" :key="cap" class="cap">{{ capLabel[cap] || cap }}</span>
          </div>
        </div>
      </div>
    </UiCard>

    <div class="hint">
      权限按「角色 → 能力」判定：主人拥有全部能力；所有者/管理员/编辑依次减少；
      插件里旧的 <code>#[Rule(permission: PermRule::OWNER)]</code> 由角色推导出等级继续生效，
      新代码可以直接调 <code>$this-&gt;can('group.config')</code>。
      值存在 <code>memberPermission.&lt;QQ&gt;</code>，v1 的 supervisor/director/manager 会自动映射成新角色。
    </div>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.page-head h1 { margin: 0; font-size: 19px; }
.sub { margin: 4px 0 0; font-size: 12px; color: var(--text-faint); line-height: 1.7; }

.stat-row { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; }
.stat { padding: 12px 14px; border: 1px solid var(--border-soft); border-radius: var(--radius-sm); background: var(--bg-card); }
.stat-value { font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-value.small { font-size: 12px; font-weight: 500; display: flex; flex-wrap: wrap; gap: 6px; }
.stat-label { margin-top: 2px; font-size: 11px; color: var(--text-faint); }
.chip { padding: 1px 6px; border-radius: 3px; border: 1px solid var(--border-soft); }

.add-row { display: flex; gap: 8px; flex-wrap: wrap; }
.qq-input { flex: 1 1 180px; }
.level-select { width: 180px; }
.mono { font-family: var(--font-mono); font-size: 12px; }
.dim { color: var(--text-faint); font-size: 12px; }
.matrix { display: flex; flex-direction: column; gap: 8px; }
.matrix-row { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
.matrix-role { width: 110px; font-size: 12.5px; color: var(--text-dim); flex: 0 0 auto; }
.matrix-level { margin-left: 6px; font-size: 10.5px; color: var(--text-faint); }
.matrix-caps { display: flex; flex-wrap: wrap; gap: 5px; flex: 1 1 auto; }
.cap { padding: 1px 7px; border-radius: 3px; border: 1px solid var(--border-soft); font-size: 11px; color: var(--text-dim); }
.hint { font-size: 11.5px; color: var(--text-faint); line-height: 1.8; }
.hint code { font-family: var(--font-mono); }
.form-error {
  margin-top: 10px; padding: 7px 10px; border-radius: var(--radius-sm);
  border: 1px solid var(--danger, #d33); color: var(--danger, #d33);
  background: color-mix(in srgb, var(--danger, #d33) 8%, transparent);
  font-size: 12px; line-height: 1.6; user-select: text;
}
</style>