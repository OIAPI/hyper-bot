import { ref } from 'vue'

const KEY = 'hyper-account-scope'

/** 全局当前机器人账号：所有 WebUI 请求都会带 X-Account-Id */
export const scopedAccount = ref(localStorage.getItem(KEY) ?? '')

export function setScopedAccount(id: string): void {
  const value = id ?? ''
  scopedAccount.value = value
  if (value !== '') localStorage.setItem(KEY, value)
  else localStorage.removeItem(KEY)
  window.dispatchEvent(new CustomEvent('hyper:account-changed', { detail: value }))
}
