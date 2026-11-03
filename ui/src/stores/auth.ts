import { computed } from 'vue'
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authApi } from '@/api'
import { getToken, setToken, clearToken } from '@/api/client'
import type { AuthUser, RoleName } from '@/types/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(getToken())
  const user = ref<AuthUser | null>(null)
  const initialized = ref(true)
  const loading = ref(false)
  const role = ref<RoleName>('viewer')
  const permissions = ref<string[]>([])

  /** 是否具备某个能力（只用来隐藏入口，真正拦截在服务端） */
  function can(capability: string): boolean {
    return permissions.value.includes(capability)
  }

  const isOwner = computed(() => role.value === 'owner')

  const isLoggedIn = () => token.value !== ''

  async function checkStatus(): Promise<void> {
    try {
      const status = await authApi.status()
      initialized.value = status.initialized
    } catch {
      // 状态接口失败不应该阻塞登录页展示
      initialized.value = true
    }
  }

  async function login(username: string, password: string): Promise<void> {
    loading.value = true
    try {
      const res = await authApi.login(username, password)
      token.value = res.token
      setToken(res.token)
      user.value = res.user
      role.value = res.role ?? 'viewer'
      permissions.value = res.permissions ?? []
    } finally {
      loading.value = false
    }
  }

  async function loadMe(): Promise<void> {
    if (!token.value) return
    try {
      const res = await authApi.me()
      user.value = res.user
      role.value = res.role
      permissions.value = res.permissions ?? []
    } catch {
      logout()
    }
  }

  function logout(): void {
    token.value = ''
    user.value = null
    role.value = 'viewer'
    permissions.value = []
    clearToken()
  }

  return { token, user, role, permissions, isOwner, can, initialized, loading, isLoggedIn, checkStatus, login, loadMe, logout }
})
