/** 业务接口封装：一个方法对应一个后端路由，路径与 webui/Routes.php 一一对应。 */

import { http } from './client'
import type {
  AuthStatus, LoginResult, AuthMe, UserList,
  Overview, OverviewCounts, TimelineEvent,
  ProtocolStatus, ProtocolName, ProtocolDetail,
  SettingsData, SettingsUpdateResult,
  SubscriptionData, SubscriptionExplain, SubscriptionCatalogData,
  GroupList, Group, PluginList, PluginImportResult,
  LogTail, LogDates, MessageRecord,
  RuntimeData, HealthData, DiskData, RouteItem,
} from '@/types/api'

/** 鉴权 */
export const authApi = {
  status: () => http.get<AuthStatus>('/auth/status', { anonymous: true }),
  login: (username: string, password: string) =>
    http.post<LoginResult>('/auth/login', { username, password }, { anonymous: true }),
  logout: () => http.post<null>('/auth/logout'),
  me: () => http.get<AuthMe>('/auth/me'),
  changePassword: (oldPassword: string, newPassword: string) =>
    http.post<null>('/auth/password', { old_password: oldPassword, new_password: newPassword }),
}

/** 账号管理（仅主人可用；服务端会再校验一次） */
export const usersApi = {
  list: () => http.get<UserList>('/users'),
  create: (payload: { username: string; password: string; role: string }) =>
    http.post<UserList>('/users', payload),
  setRole: (username: string, role: string) =>
    http.patch<UserList>('/users/' + encodeURIComponent(username), { role }),
  remove: (username: string) =>
    http.del<UserList>('/users/' + encodeURIComponent(username)),
  resetPassword: (username: string, password: string) =>
    http.post<null>('/users/' + encodeURIComponent(username) + '/password', { password }),
}

/** 概览 */
export const overviewApi = {
  index: () => http.get<Overview>('/overview'),
  counts: () => http.get<OverviewCounts>('/overview/counts'),
  protocols: () => http.get<Record<string, ProtocolStatus>>('/overview/protocols'),
  events: (limit = 20, type?: string) =>
    http.get<TimelineEvent[]>('/overview/events', { query: { limit, type } }),
}

/** 协议（账号连接） */
export const protocolApi = {
  list: () => http.get<Record<string, ProtocolStatus>>('/protocols'),
  show: (name: ProtocolName) => http.get<ProtocolDetail>('/protocols/' + name),
  update: (name: ProtocolName, config: Record<string, unknown>) =>
    http.put<{ applied: string[] }>('/protocols/' + name, { config }),
  actions: (name: ProtocolName) =>
    http.get<{ supported: Array<{ action: string }>; unsupported: Array<{ action: string; reason: string }> }>(
      '/protocols/' + name + '/actions',
    ),
  endpoints: (name: ProtocolName) =>
    http.get<Array<{ label: string; url: string; kind: string }>>('/protocols/' + name + '/endpoints'),
}

/** 配置 */
export const settingsApi = {
  get: () => http.get<SettingsData>('/settings'),
  raw: () => http.get<{ text: string }>('/settings/raw'),
  update: (key: string, value: unknown) => http.put<SettingsUpdateResult>('/settings', { key, value }),
  reload: () => http.post<{ warnings: string[] }>('/settings/reload'),
}

/** 订阅 */
export const subscriptionApi = {
  get: () => http.get<SubscriptionData>('/subscription'),
  update: (payload: Record<string, unknown>) => http.put<{ subscription: unknown; needs_restart: boolean }>('/subscription', payload),
  explain: () => http.get<SubscriptionExplain>('/subscription/explain'),
  catalog: () => http.get<SubscriptionCatalogData>('/subscription/catalog'),
  /** 面板里添加一个可订阅类型（落盘到 runtime/subscription-custom.json） */
  addCatalogItem: (payload: { type: string; label: string; group?: string }) =>
    http.post<SubscriptionCatalogData>('/subscription/catalog', payload),
  removeCatalogItem: (type: string) =>
    http.del<SubscriptionCatalogData>('/subscription/catalog/' + encodeURIComponent(type)),
}

/** 群 */
export const groupApi = {
  list: (q?: string) => http.get<GroupList>('/groups', { query: { q } }),
  show: (id: string) => http.get<Group>('/groups/' + id),
  create: (payload: Record<string, unknown>) => http.post<Group>('/groups', payload),
  toggleStatus: (id: string) => http.post<Group>('/groups/' + id + '/status'),
  togglePlugin: (id: string, plugin: string) =>
    http.post<Group>('/groups/' + id + '/plugins/' + plugin),
  destroy: (id: string) => http.del<null>('/groups/' + id),
}

/** 插件 */
export const pluginApi = {
  list: () => http.get<PluginList>('/plugins'),
  show: (name: string) => http.get<Record<string, unknown>>('/plugins/' + name),
  reload: () => http.post<{ total: number }>('/plugins/reload'),
  /**
   * 上传 zip 安装。整包当 raw body 走（不走 multipart），
   * 名字由后端从文件名推导，name 只在想覆盖时传。
   */
  importZip: (file: File, name?: string) =>
    http.postRaw<PluginImportResult>('/plugins/import/zip', file, {
      query: { filename: file.name, name },
      contentType: 'application/zip',
    }),
  /** 从 git 仓库克隆安装 */
  importGit: (payload: { url: string; branch?: string; name?: string }) =>
    http.post<PluginImportResult>('/plugins/import/git', payload),
}

/** 日志 */
export const logApi = {
  tail: (limit = 120, level?: string) => http.get<LogTail>('/logs/tail', { query: { limit, level } }),
  dates: () => http.get<LogDates>('/logs/dates'),
  day: (date: string) => http.get<LogTail>('/logs/' + date),
  messages: (date?: string) => http.get<MessageRecord[]>('/messages', { query: { date } }),
}

/** 运行时 */
export const runtimeApi = {
  get: () => http.get<RuntimeData>('/runtime'),
  update: (arch: string) => http.put<{ arch: string; needs_restart: boolean }>('/runtime', { arch }),
}

/** 系统 */
export const systemApi = {
  health: () => http.get<HealthData>('/health', { anonymous: true }),
  disk: () => http.get<DiskData>('/system/disk'),
  routes: () => http.get<RouteItem[]>('/system/routes'),
  clearCache: () => http.post<null>('/system/cache'),
}
