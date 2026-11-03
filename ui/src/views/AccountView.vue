<script setup lang="ts">
/**
 * 账号：当前身份、修改密码、退出登录；主人额外有「账号管理」（RBAC 的最小闭环）。
 *
 * 角色只有三档：owner（主人）/ admin（管理员）/ viewer（只读）。
 * 前端用 auth.can() 隐藏入口，服务端 UsersController 里 guard('user.manage') 再拦一次。
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi, oauthApi, usersApi } from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { ApiError } from '@/api/client'
import type { AuthMe, OAuthProvider, RoleName, UserRecord } from '@/types/api'
import type { UiColumn } from '@/ui/types'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()

const me = ref<AuthMe | null>(null)
const oldPass = ref('')
const newPass = ref('')
const confirmPass = ref('')
const submitting = ref(false)

const ROLE_LABEL: Record<string, string> = { owner: '主人', admin: '管理员', viewer: '只读' }

const meItems = computed(() => [
  { label: '用户名', value: me.value?.user.username ?? '—' },
  { label: '角色', value: ROLE_LABEL[me.value?.role ?? ''] ?? '—' },
  {
    label: '可用能力',
    value: (me.value?.permissions ?? []).join('、') || '—',
  },
])

/* ---------------- 账号管理（主人） ---------------- */

const users = ref<UserRecord[]>([])
const roleOptions = [
  { label: '主人', value: 'owner' },
  { label: '管理员', value: 'admin' },
  { label: '只读', value: 'viewer' },
]
const userColumns: UiColumn[] = [
  { key: 'username', title: '用户名', minWidth: 140 },
  { key: 'role', title: '角色', width: 150 },
  { key: 'created', title: '创建时间', width: 170 },
  { key: 'ops', title: '操作', width: 150, align: 'right' },
]
const loadingUsers = ref(false)

async function loadUsers(): Promise<void> {
  if (!auth.can('user.manage')) return
  loadingUsers.value = true
  try {
    const res = await usersApi.list()
    users.value = res.items ?? []
  } catch (e) {
    toast.fromError(e, '加载账号失败')
  } finally {
    loadingUsers.value = false
  }
}

const createVisible = ref(false)
const newUser = ref({ username: '', password: '', role: 'viewer' as RoleName })
const creating = ref(false)

function openCreate(): void {
  newUser.value = { username: '', password: '', role: 'viewer' }
  createVisible.value = true
}

async function createUser(): Promise<void> {
  if (creating.value) return
  creating.value = true
  try {
    const res = await usersApi.create({ ...newUser.value })
    users.value = res.items ?? []
    createVisible.value = false
    toast.ok('账号已创建')
  } catch (e) {
    toast.fromError(e, '创建失败')
  } finally {
    creating.value = false
  }
}

async function changeRole(row: UserRecord, role: string): Promise<void> {
  try {
    const res = await usersApi.setRole(row.username, role)
    users.value = res.items ?? []
    toast.ok('角色已更新')
  } catch (e) {
    toast.fromError(e, '更新失败')
  }
}

async function removeUser(row: UserRecord): Promise<void> {
  try {
    const res = await usersApi.remove(row.username)
    users.value = res.items ?? []
    toast.ok('账号已删除')
  } catch (e) {
    toast.fromError(e, '删除失败')
  }
}

const resetVisible = ref(false)
const resetTarget = ref('')
const resetPass = ref('')

function openReset(row: UserRecord): void {
  resetTarget.value = row.username
  resetPass.value = ''
  resetVisible.value = true
}

async function submitReset(): Promise<void> {
  try {
    await usersApi.resetPassword(resetTarget.value, resetPass.value)
    resetVisible.value = false
    toast.ok('密码已重置')
  } catch (e) {
    toast.fromError(e, '重置失败')
  }
}

/* ---------------- 第三方登录 ---------------- */

const providers = ref<OAuthProvider[]>([])
const loadingProviders = ref(false)
const bindingId = ref('')

async function loadProviders(): Promise<void> {
  loadingProviders.value = true
  try {
    const res = await oauthApi.providers()
    providers.value = res.providers ?? []
  } catch (e) {
    toast.fromError(e, '加载第三方登录配置失败')
  } finally {
    loadingProviders.value = false
  }
}

/** 绑定是浏览器整页跳转（第三方域名），回来后由 URL 上的 oauth 参数提示结果 */
function bindProvider(row: OAuthProvider): void {
  if (!row.enabled) {
    toast.warn('服务端还没配置 ' + row.label + ' 的 client_id / client_secret')
    return
  }
  window.location.href = oauthApi.startUrl(row.id, true)
}

async function unbindProvider(row: OAuthProvider): Promise<void> {
  bindingId.value = row.id
  try {
    await oauthApi.unbind(row.id)
    toast.ok('已解绑 ' + row.label)
    await loadProviders()
  } catch (e) {
    toast.fromError(e, '解绑失败')
  } finally {
    bindingId.value = ''
  }
}

/** 第三方回调会带着 ?oauth=bound|bind_failed|login|unbound|oauth_error&message=... 回来 */
function consumeOAuthResult(): void {
  const query = router.currentRoute.value.query
  const status = String(query.oauth ?? '')
  if (!status) return
  const message = String(query.message ?? '')
  if (status === 'bound' || status === 'login') toast.ok(message || '操作成功')
  else toast.warn(message || '第三方登录未完成')
  const rest = { ...query }
  delete rest.oauth
  delete rest.message
  void router.replace({ query: rest })
}

/* ---------------- 当前账号 ---------------- */

onMounted(async () => {
  try {
    me.value = await authApi.me()
    auth.user = me.value.user
    auth.role = me.value.role
    auth.permissions = me.value.permissions
  } catch {
    me.value = null
  }
  consumeOAuthResult()
  await Promise.all([loadUsers(), loadProviders()])
})

async function changePassword(): Promise<void> {
  if (newPass.value.length < 8) {
    toast.warn('新密码至少 8 位')
    return
  }
  if (newPass.value !== confirmPass.value) {
    toast.warn('两次输入的新密码不一致')
    return
  }
  submitting.value = true
  try {
    await authApi.changePassword(oldPass.value, newPass.value)
    toast.ok('密码已更新，请用新密码重新登录')
    oldPass.value = newPass.value = confirmPass.value = ''
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : '修改失败')
  } finally {
    submitting.value = false
  }
}

async function logout(): Promise<void> {
  try {
    await authApi.logout()
  } catch {
    // 服务端失败也清本地
  }
  auth.logout()
  void router.replace({ name: 'login' })
}
</script>

<template>
  <div class="page account">
    <header class="page-head">
      <div>
        <h1>账号</h1>
        <p class="sub">当前登录身份、权限、密码与账号管理。</p>
      </div>
    </header>

    <UiCard>
      <template #header>
        <div class="card-head-row">
          <span class="card-title">当前账号</span>
          <div class="spacer" />
          <UiTag type="accent" size="small">{{ me?.user.username ?? '—' }}</UiTag>
        </div>
      </template>
      <UiDescriptions :items="meItems" :column="1" border size="small" />
    </UiCard>

    <UiCard>
      <template #header>
        <div class="card-head-row">
          <span class="card-title">第三方登录</span>
          <span class="tiny faint">绑定后可用该平台一键登录</span>
          <div class="spacer" />
          <UiButton size="small" text :loading="loadingProviders" @click="loadProviders">刷新</UiButton>
        </div>
      </template>

      <div v-if="providers.length" class="oauth-list">
        <div v-for="p in providers" :key="p.id" class="oauth-row">
          <div class="grow">
            <div class="small strong">{{ p.label }}</div>
            <div class="tiny faint">
              <template v-if="!p.enabled">服务端未配置 client_id / client_secret</template>
              <template v-else-if="p.bound">
                已绑定 {{ p.bound_label || '该平台账号' }}
                <span v-if="p.bound_at"> · {{ new Date(p.bound_at * 1000).toLocaleDateString() }}</span>
              </template>
              <template v-else>未绑定</template>
            </div>
          </div>
          <UiTag v-if="p.bound" type="success" size="small">已绑定</UiTag>
          <UiButton
            v-if="p.bound"
            size="small"
            text
            type="danger"
            :loading="bindingId === p.id"
            @click="unbindProvider(p)"
          >
            解绑
          </UiButton>
          <UiButton v-else size="small" :disabled="!p.enabled" @click="bindProvider(p)">绑定</UiButton>
        </div>
      </div>
      <UiEmpty v-else description="没有可用的第三方登录方式" />
    </UiCard>

    <UiCard>
      <div class="logout-row">
        <div class="grow">
          <div class="small strong">机器人账号</div>
          <div class="tiny faint">同一协议可以挂多个机器人号，各自一套接入地址与凭据</div>
        </div>
        <UiButton size="small" @click="router.push({ name: 'accounts' })">管理账号</UiButton>
      </div>
    </UiCard>

    <UiCard v-if="auth.can('user.manage')">
      <template #header>
        <div class="card-head-row">
          <span class="card-title">账号管理</span>
          <span class="tiny faint">主人专属 · 三档角色</span>
          <div class="spacer" />
          <UiButton size="small" type="primary" @click="openCreate">新增账号</UiButton>
        </div>
      </template>

      <UiTable v-if="users.length" :data="users" :columns="userColumns" size="small">
        <template #cell-role="{ row }">
          <UiSelect
            :model-value="(row as UserRecord).role"
            :options="roleOptions"
            size="small"
            @update:model-value="(v: any) => changeRole(row as UserRecord, String(v))"
          />
        </template>
        <template #cell-created="{ row }">
          <span class="tiny faint">{{ new Date((row as UserRecord).created * 1000).toLocaleString() }}</span>
        </template>
        <template #cell-ops="{ row }">
          <div class="row gap-6" style="justify-content: flex-end">
            <UiButton size="small" text @click="openReset(row as UserRecord)">重置密码</UiButton>
            <UiButton size="small" text type="danger" @click="removeUser(row as UserRecord)">删除</UiButton>
          </div>
        </template>
      </UiTable>
      <UiEmpty v-else description="还没有其他账号" />
    </UiCard>

    <UiCard>
      <template #header><span class="card-title">修改密码</span></template>
      <UiForm label-position="top" class="pwd-form">
        <UiFormItem label="当前密码">
          <UiInput v-model="oldPass" type="password" autocomplete="current-password" />
        </UiFormItem>
        <UiFormItem label="新密码">
          <UiInput v-model="newPass" type="password" autocomplete="new-password" placeholder="至少 8 位" />
        </UiFormItem>
        <UiFormItem label="确认新密码">
          <UiInput v-model="confirmPass" type="password" autocomplete="new-password" />
        </UiFormItem>
        <UiButton type="primary" size="small" :loading="submitting" @click="changePassword">
          {{ submitting ? '提交中…' : '更新密码' }}
        </UiButton>
      </UiForm>
    </UiCard>

    <UiCard>
      <div class="logout-row">
        <div class="grow">
          <div class="small strong">退出登录</div>
          <div class="tiny faint">清除本机保存的令牌</div>
        </div>
        <UiButton size="small" type="danger" plain @click="logout">退出</UiButton>
      </div>
    </UiCard>

    <UiDialog v-model="createVisible" title="新增账号" width="420px">
      <UiForm label-position="top">
        <UiFormItem label="用户名">
          <UiInput v-model="newUser.username" placeholder="3-32 位，字母/数字/._-" />
        </UiFormItem>
        <UiFormItem label="初始密码">
          <UiInput v-model="newUser.password" type="password" placeholder="至少 8 位" />
        </UiFormItem>
        <UiFormItem label="角色">
          <UiSelect v-model="newUser.role" :options="roleOptions" />
        </UiFormItem>
      </UiForm>
      <template #footer>
        <UiButton size="small" @click="createVisible = false">取消</UiButton>
        <UiButton size="small" type="primary" :loading="creating" @click="createUser">创建</UiButton>
      </template>
    </UiDialog>

    <UiDialog v-model="resetVisible" :title="'重置 ' + resetTarget + ' 的密码'" width="380px">
      <UiInput v-model="resetPass" type="password" placeholder="新密码，至少 8 位" />
      <template #footer>
        <UiButton size="small" @click="resetVisible = false">取消</UiButton>
        <UiButton size="small" type="primary" @click="submitReset">确定</UiButton>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 14px; }
.page-head { margin-bottom: 0; align-items: flex-end; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }
.account { max-width: 860px; }
.pwd-form { display: flex; flex-direction: column; gap: 2px; }
.logout-row { display: flex; align-items: center; gap: 14px; }
.oauth-list { display: flex; flex-direction: column; gap: 2px; }
.oauth-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid var(--border-soft);
}
.oauth-row:last-child { border-bottom: none; }
</style>
