<script setup lang="ts">
/**
 * QQ 成员权限：机器人认人的等级，决定谁能用管理命令。
 *
 * 注意：这里的权限只影响机器人在群里的行为，和「账号设置」里能登录后台的账号
 * 完全是两套东西 —— 面板账号用用户名密码，这里用 QQ 号。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { permissionApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { scopedAccount, setScopedAccount } from '@/stores/accountScope'
import type { PermissionsData } from '@/types/api'
import type { UiColumn } from '@/ui/types'
import { sortRows, type SortState } from '@/utils/sortRows'

const auth = useAuthStore()
const toast = useToastStore()

const data = ref<PermissionsData | null>(null)
const loading = ref(false)
const canWrite = computed(() => auth.can('settings.write'))
const selectedAccount = scopedAccount
const accountOptions = computed(() =>
  (data.value?.accounts ?? []).map((a) => ({
    label: a.name ? a.name + '（' + a.id + '）' : a.id,
    value: a.id,
  })),
)
watch(selectedAccount, (v) => {
  setScopedAccount(v)
  void load()
})

const columns: UiColumn[] = [
  { key: 'qq', title: 'QQ / 成员 ID', width: 220 },
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

/* ---------------- 搜索 / 筛选 / 分页 ---------------- */

const keyword = ref('')
const roleFilter = ref('')
const page = ref(1)
const pageSize = ref(20)

const roleOptions = computed(() => [{ label: '全部角色', value: '' }, ...levels.value])
const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  const role = roleFilter.value
  return rows.value.filter((row) => {
    if (role !== '' && row.role !== role) return false
    if (kw === '') return true
    return row.qq.toLowerCase().includes(kw)
      || (row.label || '').toLowerCase().includes(kw)
      || (row.role || '').toLowerCase().includes(kw)
  })
})
const sort = ref<SortState | null>(null)
const roleLevel = computed(() => {
  const map: Record<string, number> = {}
  for (const item of matrix.value) map[item.role] = item.level
  return map
})
const sorted = computed(() => sortRows(filtered.value, sort.value, {
  qq: (row) => row.qq,
  role: (row) => row.role,
  level: (row) => roleLevel.value[row.role] ?? 0,
  label: (row) => row.label,
}))
const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize.value)))
const paged = computed(() => sorted.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))

watch([keyword, roleFilter, pageSize, sort], () => { page.value = 1 })
watch(pageCount, (n) => { if (page.value > n) page.value = n })

const emptyText = computed(() => {
  if (rows.value.length === 0) return '还没有单独授权的成员'
  return '没有匹配的成员，换个关键词试试'
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
const diag = computed(() => (data.value as any)?.diagnose ?? null)
/** 列表为空时把「为什么空」说清楚：面板存储 / 机器人 Redis 各自的条数与错误 */
const emptyHint = computed(() => {
  const d = diag.value
  if (!d) return ''
  const parts: string[] = []
  parts.push('面板存储（' + d.store_driver + '）里 ' + d.store_keys + ' 条')
  parts.push('机器人 Redis ' + d.redis_target + '：' + (d.redis_ok
    ? '已连接，' + d.redis_keys + ' 条'
    : '不可用（' + (d.redis_error || '未知原因') + '）'))
  return parts.join('；')
})
const adding = ref(false)
const addError = ref('')

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await permissionApi.list(selectedAccount.value || undefined)
    if (!selectedAccount.value && data.value?.account) {
      selectedAccount.value = data.value.account
    }
  } catch (e) {
    toast.fromError(e, '加载权限失败')
  } finally {
    loading.value = false
  }
}

async function add(): Promise<void> {
  addError.value = ''
  if (!/^[A-Za-z0-9_-]{5,64}$/.test(newQq.value.trim())) {
    addError.value = 'QQ 号或官方成员 openid 格式不正确'
    return
  }
  adding.value = true
  try {
    data.value = await permissionApi.save(newQq.value.trim(), newLevel.value, selectedAccount.value || undefined)
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
    const res = await permissionApi.save(row.qq, level, selectedAccount.value || undefined)
    // 局部响应（只有 items/stats）直接覆盖会让 template 里的 levels 变 undefined
    data.value = { ...(data.value ?? {}), ...res } as PermissionsData
    toast.ok(row.qq + ' → ' + (levels.value.find((l) => l.value === level)?.label ?? level))
  } catch (e) {
    toast.fromError(e, '修改失败')
    await load()
  }
}

async function remove(row: { qq: string }): Promise<void> {
  try {
    const res = await permissionApi.remove(row.qq, selectedAccount.value || undefined)
    data.value = { ...(data.value ?? {}), ...res } as PermissionsData
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
      权限按机器人账号隔离：顶部选择哪个机器人，就只管理那个号的群成员权限。
    </UiAlert>

    <div class="stat-row">
      <div class="stat"><div class="stat-value">{{ data?.stats?.total ?? 0 }}</div><div class="stat-label">已单独授权</div></div>
      <div class="stat wide">
        <div class="stat-value small">
          <span v-for="(count, level) in (data?.stats?.by_level ?? {})" :key="level" class="chip">
            {{ data?.levels?.[level] || level }} · {{ count }}
          </span>
        </div>
        <div class="stat-label">等级分布</div>
      </div>
    </div>

    <UiCard v-if="canWrite">
      <template #header><span class="card-title">添加 / 修改</span></template>
      <div class="add-row">
        <UiInput v-model="newQq" class="qq-input" placeholder="QQ 号，或官方成员 openid" />
        <UiSelect v-model="newLevel" :options="levels" class="level-select" />
        <UiButton size="small" type="primary" :loading="adding" @click="add">保存</UiButton>
      </div>
      <div v-if="addError" class="form-error">{{ addError }}</div>
    </UiCard>

    <UiAlert v-if="data && rows.length === 0" type="warning" :closable="false">
      还没有读到任何权限记录。{{ emptyHint }}
      <br />
      如果确认机器人 Redis 里有数据但仍为空，说明面板连的不是同一个库：检查 Config.ini 的
      <code>[Redis] host / port / auth / select</code>，或装 php-redis 扩展。
    </UiAlert>

    <UiCard :padding="'none'">
      <div class="toolbar">
        <UiSelect v-model="selectedAccount" :options="accountOptions" class="account-select" />
        <UiInput
          v-model="keyword"
          class="search"
          placeholder="搜索 QQ 号或权限名称"
          clearable
        />
        <UiSelect v-model="roleFilter" :options="roleOptions" class="role-filter" />
        <div class="toolbar-spacer" />
        <span class="count">
          {{ filtered.length }} / {{ rows.length }} 人
        </span>
        <UiSelect
          v-model="pageSize"
          :options="[{ label: '每页 10', value: 10 }, { label: '每页 20', value: 20 }, { label: '每页 50', value: 50 }, { label: '每页 100', value: 100 }]"
          class="page-size"
        />
      </div>
      <UiTable
        :data="paged"
        :columns="columns"
        row-key="qq"
        :loading="loading"
        :empty-text="emptyText"
        :sort="sort"
        :sort-keys="{ level: '' }"
        @update:sort="sort = $event"
      >
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
      <div v-if="filtered.length > pageSize" class="pager">
        <UiButton size="small" :disabled="page <= 1" @click="page = 1">首页</UiButton>
        <UiButton size="small" :disabled="page <= 1" @click="page -= 1">上一页</UiButton>
        <span class="page-text">第 {{ page }} / {{ pageCount }} 页</span>
        <UiButton size="small" :disabled="page >= pageCount" @click="page += 1">下一页</UiButton>
        <UiButton size="small" :disabled="page >= pageCount" @click="page = pageCount">末页</UiButton>
      </div>
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

.toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 12px; border-bottom: 1px solid var(--border-soft); }
.toolbar-spacer { flex: 1; }
.search { flex: 1 1 220px; min-width: 160px; }
.role-filter { width: 160px; }
.page-size { width: 120px; }
.count { font-size: 11.5px; color: var(--text-faint); }
.pager { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px; flex-wrap: wrap; border-top: 1px solid var(--border-soft); }
.page-text { font-size: 12px; color: var(--text-dim); }

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