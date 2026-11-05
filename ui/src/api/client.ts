/**
 * HTTP 客户端。
 *
 * 两个约定必须记住：
 *  1. 后端统一返回 { code, message, data }，code === 1 才是成功；
 *  2. 鉴权用 Bearer token，401 时要踢回登录页（不能只弹个错就完事，
 *     否则用户会盯着一个永远空的页面）。
 */

import type { ApiEnvelope } from '@/types/api'

const TOKEN_KEY = 'hyper-token'

export class ApiError extends Error {
  constructor(
    message: string,
    readonly code: number = -1,
    readonly status: number = 0,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) ?? ''
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

/** 401 时由路由层注册的处理器接管（跳登录页） */
let unauthorizedHandler: (() => void) | null = null
export function onUnauthorized(fn: () => void): void {
  unauthorizedHandler = fn
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'POST' | 'PUT' | 'DELETE'

interface RequestOptions {
  query?: Record<string, string | number | boolean | undefined>
  body?: unknown
  /**
   * 原始请求体（File / Blob / string）。
   * 插件 zip 上传走这里：Swoole 默认的 multipart 解析要落盘到 upload_tmp_dir，
   * 本机 /tmp 不可写，所以干脆整包当 raw body 发，后端自己写文件。
   */
  raw?: Blob | string
  /** raw 的 Content-Type，缺省 application/octet-stream */
  contentType?: string
  /** 登录接口本身不需要带 token，也不该在 401 时触发跳转 */
  anonymous?: boolean
  signal?: AbortSignal
  /** 超时毫秒数，默认 20s：请求挂住时不能无限等 */
  timeoutMs?: number
}

const BASE = '/webui/api'

async function request<T>(method: Method, path: string, opts: RequestOptions = {}): Promise<T> {
  const url = new URL(BASE + path, window.location.origin)
  if (opts.query) {
    for (const [k, v] of Object.entries(opts.query)) {
      if (v !== undefined && v !== '') url.searchParams.set(k, String(v))
    }
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getToken()
  if (token && !opts.anonymous) headers.Authorization = 'Bearer ' + token
  // 全局机器人账号：后端按该账号隔离群、权限、订阅、Webhook、RSS、定时任务等
  const scopedAccount = localStorage.getItem('hyper-account-scope') ?? ''
  if (scopedAccount !== '') headers['X-Account-Id'] = scopedAccount

  let payloadBody: BodyInit | undefined
  if (opts.raw !== undefined) {
    payloadBody = opts.raw
    headers['Content-Type'] = opts.contentType ?? 'application/octet-stream'
  } else if (opts.body !== undefined) {
    payloadBody = JSON.stringify(opts.body)
    headers['Content-Type'] = 'application/json'
  }

  // 超时兜底：接口卡住时页面会一直等，用户只看到空白
  const ctrl = new AbortController()
  const timeoutMs = opts.timeoutMs ?? 20000
  let timedOut = false
  const timer = window.setTimeout(() => {
    timedOut = true
    ctrl.abort()
  }, timeoutMs)
  if (opts.signal) {
    if (opts.signal.aborted) ctrl.abort()
    else opts.signal.addEventListener('abort', () => ctrl.abort(), { once: true })
  }

  let res: Response
  try {
    res = await fetch(url.toString(), {
      method,
      headers,
      body: payloadBody,
      signal: ctrl.signal,
    })
  } catch (e) {
    if ((e as Error).name === 'AbortError') {
      if (timedOut) throw new ApiError('请求超时（' + Math.round(timeoutMs / 1000) + 's）：' + path, -1, 0)
      throw e
    }
    throw new ApiError('网络请求失败，请检查服务是否在运行', -1, 0)
  } finally {
    window.clearTimeout(timer)
  }

  // 401：只有非匿名请求才踢人
  if (res.status === 401 && !opts.anonymous) {
    clearToken()
    unauthorizedHandler?.()
    throw new ApiError('登录已过期，请重新登录', -401, 401)
  }

  // 413 不是我们的业务错误：反代（nginx 默认 client_max_body_size 1m）直接拒了请求体，
  // PHP 根本没收到，日志里往往还伴随 104/111。这里给出可执行的提示，别让用户猜。
  if (res.status === 413) {
    throw new ApiError(
      '上传被服务器拒绝（413）：反代限制了请求体大小。请把 nginx 的 client_max_body_size 调到 64m 以上，' +
        '或改用「从 URL 更新」（服务器自己下载，不经过浏览器上传）',
      -413,
      413,
    )
  }
  if (res.status === 502 || res.status === 503 || res.status === 504) {
    throw new ApiError(
      '上游服务不可用（' + res.status + '）：反代连不上 PHP。检查 php-fpm 是否在跑、端口/套接字是否与 nginx 一致',
      -1,
      res.status,
    )
  }

  const text = await res.text()
  let payload: ApiEnvelope<T> | null = null
  if (text) {
    try {
      payload = JSON.parse(text) as ApiEnvelope<T>
    } catch {
      throw new ApiError('服务端返回了非 JSON 内容：' + text.slice(0, 120), -1, res.status)
    }
  }

  if (!payload) throw new ApiError('服务端返回空响应', -1, res.status)

  if (payload.code !== 1) {
    throw new ApiError(payload.message || '请求失败', payload.code, res.status)
  }
  return payload.data
}

export const http = {
  get: <T>(path: string, opts?: RequestOptions) => request<T>('GET', path, opts),
  post: <T>(path: string, body?: unknown, opts?: RequestOptions) =>
    request<T>('POST', path, { ...opts, body }),
  put: <T>(path: string, body?: unknown, opts?: RequestOptions) =>
    request<T>('PUT', path, { ...opts, body }),
  patch: <T>(path: string, body?: unknown, opts?: RequestOptions) =>
    request<T>('PATCH', path, { ...opts, body }),
  del: <T>(path: string, opts?: RequestOptions) => request<T>('DELETE', path, opts),
  /** 原始字节上传：不转 JSON，直接把 Blob/File 塞进 body */
  postRaw: <T>(path: string, raw: Blob | string, opts?: RequestOptions) =>
    request<T>('POST', path, { ...opts, raw }),
}