/** 业务接口封装：一个方法对应一个后端路由，路径与 webui/Routes.php 一一对应。 */

import { http } from './client'
import type {
  AuthStatus, LoginResult, AuthMe, UserList,
  Overview, OverviewCounts, TimelineEvent,
  ProtocolStatus, ProtocolName, ProtocolDetail,
  SettingsData, SettingsUpdateResult,
  SubscriptionData, SubscriptionExplain, SubscriptionCatalogData, GroupOverride,
  GroupList, Group, PluginList, PluginImportResult,
  LogTail, LogDates, MessageRecord,
  RuntimeData, HealthData, DiskData, RouteItem,
  BotAccount, BotAccountList, OAuthProviders,
  ScheduleData, ScheduleJob, PermissionsData, ExtWidget,
  UpdateInfo, UpdateCheckResult, UpdateResult, UpdateJob, UpdateStartResult, ChunkInitResult,
  ExtMenuItem, ExtPageData, ExtScriptItem,
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

/** 机器人账号（多账号）：一个协议可以挂多个号 */
export const accountApi = {
  list: () => http.get<BotAccountList>('/accounts'),
  create: (payload: Partial<BotAccount>) => http.post<BotAccountList>('/accounts', payload),
  update: (id: string, payload: Partial<BotAccount>) =>
    http.put<BotAccountList>('/accounts/' + encodeURIComponent(id), payload),
  remove: (id: string) => http.del<BotAccountList>('/accounts/' + encodeURIComponent(id)),
}

/** 第三方登录（OAuth2） */
export const oauthApi = {
  providers: () => http.get<OAuthProviders>('/auth/oauth/providers'),
  unbind: (id: string) => http.del<null>('/auth/oauth/' + encodeURIComponent(id)),
  /** 浏览器整页跳转，所以这里只拼 URL，不走 XHR */
  startUrl: (id: string, bind: boolean) =>
    '/webui/api/auth/oauth/' + encodeURIComponent(id) + '/start' + (bind ? '?bind=1' : ''),
}

/** 定时任务 */
export const scheduleApi = {
  list: () => http.get<ScheduleData>('/schedule'),
  create: (payload: Partial<ScheduleJob>) => http.post<{ saved: ScheduleJob }>('/schedule', payload),
  update: (id: string, payload: Partial<ScheduleJob>) =>
    http.put<{ saved: ScheduleJob }>('/schedule/' + encodeURIComponent(id), payload),
  remove: (id: string) => http.del<null>('/schedule/' + encodeURIComponent(id)),
  run: (id: string) => http.post<{ ok: boolean; message: string }>('/schedule/' + encodeURIComponent(id) + '/run'),
  preview: (cron: string) => http.post<{ ok: boolean; runs: string[]; msg: string }>('/schedule/preview', { cron }),
}

/** QQ 成员权限（机器人使用权限） */
export const permissionApi = {
  list: (account?: string) => http.get<PermissionsData>('/permissions', { query: { account } }),
  save: (qq: string, level: string, account?: string) =>
    http.post<PermissionsData>('/permissions', { qq, level }, { query: { account } }),
  remove: (qq: string, account?: string) =>
    http.del<PermissionsData>('/permissions/' + encodeURIComponent(qq), { query: { account } }),
}

/** 程序更新（仅主人） */
export const updateApi = {
  info: () => http.get<UpdateInfo>('/update'),
  check: () => http.post<UpdateCheckResult>('/update/check'),
  /** 把当前目录关联成 git 仓库（不覆盖文件） */
  attach: (payload: { url: string; branch?: string; proxy?: string }) =>
    http.post<{ commit: string; branch: string; msg: string }>('/update/attach', payload),
  fromGit: (payload: { url: string; branch?: string; proxy?: string }) =>
    http.post<UpdateResult>('/update/git', payload),
  /** 整包直传：只适合小包，大包走分片（nginx 默认 1m 请求体会 413） */
  fromZip: (file: File) =>
    http.postRaw<UpdateStartResult>('/update/zip', file, {
      query: { filename: file.name },
      contentType: 'application/zip',
    }),
  /** 异步更新的任务状态；前端轮询它拿进度与日志 */
  job: () => http.get<{ job: UpdateJob }>('/update/job'),
  /** 服务器自己下载（绕开浏览器上传与反代超时） */
  fromUrl: (payload: { url: string; proxy?: string }) =>
    http.post<UpdateStartResult>('/update/zip/url', payload, { timeoutMs: 120000 }),
  /** 分片上传：初始化 → 逐片 raw POST → 合并并启动后台更新 */
  chunkInit: (payload: { filename: string; size: number }) =>
    http.post<ChunkInitResult>('/update/chunk/init', payload),
  chunkUpload: (upload: string, index: number, blob: Blob) =>
    http.postRaw<{ upload: string; index: number; bytes: number }>(
      '/update/chunk',
      blob,
      { query: { upload, index }, contentType: 'application/octet-stream', timeoutMs: 60000 },
    ),
  chunkFinish: (upload: string) =>
    http.post<UpdateStartResult>('/update/chunk/finish', { upload }, { timeoutMs: 120000 }),
}

/** 插件后台（schema 驱动，插件自己提供菜单、页面与接口） */
export const extApi = {
  pages: () => http.get<{ items: ExtMenuItem[] }>('/ext/pages'),
  page: (plugin: string) => http.get<ExtPageData>('/ext/pages/' + encodeURIComponent(plugin)),
  call: (plugin: string, path: string, method = 'GET', params: Record<string, unknown> = {}) =>
    http.post<unknown>('/ext/' + encodeURIComponent(plugin) + '/call', { path, method, params }),
  /** 注入到已有页面的小块 */
  widgets: (slot: string) => http.get<{ slot: string; items: ExtWidget[] }>('/ext/widgets', { query: { slot } }),
  /**
   * 插件自带的前端资源（ES module / CSS）。
   * 公开静态地址由 /webui/plugin-assets/<插件>/<文件> 提供，
   * 浏览器动态 import 时不带 Authorization，所以不能走鉴权接口。
   */
  scripts: () => http.get<{ items: ExtScriptItem[] }>('/ext/scripts'),
}

/** 概览 */
export const overviewApi = {
  index: () => http.get<Overview>('/overview'),
  counts: () => http.get<OverviewCounts>('/overview/counts'),
  protocols: () => http.get<Record<string, ProtocolStatus>>('/overview/protocols'),
  events: (limit = 20, type?: string, date?: string) =>
    http.get<TimelineEvent[]>('/overview/events', { query: { limit, type, date } }),
}

/** 协议（账号连接） */
export const protocolApi = {
  list: () => http.get<Record<string, ProtocolStatus>>('/protocols'),
  show: (name: ProtocolName) => http.get<ProtocolDetail>('/protocols/' + name),
  update: (name: ProtocolName, config: Record<string, unknown>) =>
    http.put<{ applied: string[] }>('/protocols/' + name, { config }),
  /** 发送能力自检：真的调一次 get_login_info；account 指定用哪个机器人账号的 api */
  test: (name: ProtocolName, account = '') =>
    http.post<{ ok: boolean; via: string; api: string; account?: string; ms?: number; status?: string; retcode?: unknown; message?: string; data?: unknown; hint?: string }>(
      '/protocols/' + name + '/test',
      { account },
    ),
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
  /** 新增配置项：section 可以是不存在的小节（含 plugin.xxx 这种带点的） */
  add: (section: string, key: string, value: unknown) =>
    http.put<SettingsUpdateResult>('/settings', { section, key, value }),
  /** 删除配置项；whole=true 删整个小节 */
  remove: (path: string, whole = false) =>
    http.del<{ path: string; whole: boolean; backup?: string; warnings: string[] }>('/settings', {
      query: { path, whole: whole ? '1' : '0' },
    }),
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
  /** 按群覆盖列表 */
  groupOverrides: () => http.get<{ groups: Record<string, GroupOverride> }>('/subscription/groups'),
  /** 写入一个群的覆盖 */
  setGroupOverride: (group: string, payload: Partial<GroupOverride>) =>
    http.put<{ groups: Record<string, GroupOverride> }>(
      '/subscription/groups/' + encodeURIComponent(group),
      payload,
    ),
  /** 删除一个群的覆盖（恢复跟随全局） */
  removeGroupOverride: (group: string) =>
    http.del<{ groups: Record<string, GroupOverride> }>(
      '/subscription/groups/' + encodeURIComponent(group),
    ),
}

/** 群 */
export const groupApi = {
  list: (q?: string, account?: string) => http.get<GroupList>('/groups', { query: { q, account } }),
  show: (id: string, account?: string) => http.get<Group>('/groups/' + encodeURIComponent(id), { query: { account } }),
  create: (payload: Record<string, unknown>) => http.post<Group>('/groups', payload),
  /** 让协议端 get_group_list 拉一次，把群名回填到面板 */
  sync: (account?: string) => http.post<{ synced: number; total: number }>('/groups/sync', {}, { query: { account } }),
  toggleStatus: (id: string, account?: string) =>
    http.post<Group>('/groups/' + encodeURIComponent(id) + '/status', {}, { query: { account } }),
  /** 群级插件开关：必须显式带 enabled，后端默认值是 false（不带等于「关闭」） */
  togglePlugin: (id: string, plugin: string, enabled: boolean, account?: string) =>
    // 插件类是 App\Plugins\Xxx：URL 里反斜杠会被浏览器当成 /，必须编码
    http.post<Group>('/groups/' + encodeURIComponent(id) + '/plugins/' + encodeURIComponent(plugin), { enabled }, { query: { account } }),
  destroy: (id: string, account?: string) =>
    http.del<null>('/groups/' + encodeURIComponent(id), { query: { account } }),
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
  /** 全局启用 / 停用（关掉后不参与事件分发，文件仍在） */
  setEnabled: (name: string, enabled: boolean) =>
    http.put<PluginList>('/plugins/' + encodeURIComponent(name) + '/enable', { enabled }),
  /** 从 git 仓库克隆安装 */
  importGit: (payload: { url: string; branch?: string; name?: string }) =>
    http.post<PluginImportResult>('/plugins/import/git', payload),
}

/** 日志 */
export const logApi = {
  tail: (limit = 120, level?: string) => http.get<LogTail>('/logs/tail', { query: { limit, level } }),
  dates: () => http.get<LogDates>('/logs/dates'),
  day: (date: string) => http.get<LogTail>('/logs/' + date),
  /** 消息记录：不传日期时后端给「最近有数据的那天」，返回 {date,items,total,dates} */
  messages: (date?: string) =>
    http.get<{ date: string; items: MessageRecord[]; total: number; dates?: string[] }>('/messages', {
      query: { date },
    }),
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
  // 后端返回 {items,total}；这里收敛成数组，视图不必关心信封
  routes: () =>
    http
      .get<RouteItem[] | { items?: RouteItem[] }>('/system/routes')
      .then((r) => (Array.isArray(r) ? r : (r.items ?? []))),
  clearCache: () => http.post<null>('/system/cache'),
}