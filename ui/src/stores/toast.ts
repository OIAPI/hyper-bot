import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'ok' | 'error' | 'info' | 'warn'

export interface Toast {
  id: number
  kind: ToastKind
  message: string
}

let seq = 0

export const useToastStore = defineStore('toast', () => {
  const items = ref<Toast[]>([])

  function push(kind: ToastKind, message: string, ttl = 3600): void {
    const id = ++seq
    items.value.push({ id, kind, message })
    window.setTimeout(() => dismiss(id), ttl)
  }

  function dismiss(id: number): void {
    items.value = items.value.filter((t) => t.id !== id)
  }

  const ok = (m: string) => push('ok', m)
  const error = (m: string) => push('error', m, 5200)
  const info = (m: string) => push('info', m)
  const warn = (m: string) => push('warn', m, 4600)

  /** 把异常统一转成一条错误提示，避免每个调用点都写 try/catch + 判空 */
  function fromError(e: unknown, fallback = '操作失败'): void {
    const msg = e instanceof Error ? e.message : String(e)
    error(msg || fallback)
  }

  return { items, push, dismiss, ok, error, info, warn, fromError }
})
