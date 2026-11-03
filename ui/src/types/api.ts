/**
 * 后端 API 类型定义。
 *
 * 全部字段对齐 webui/Controller/*.php 与 webui/Metrics.php 的真实返回，
 * 不是凭直觉写的：例如 protocol.milky.detail.ws_in 来自 WsHub::milkyCount()，
 * qqbot.detail.status 来自 QqBot\EventClient::status()。
 *
 * 后端统一包一层 { code, message, data }，1 表示成功。
 */

/** 后端统一响应信封 */
export interface ApiEnvelope<T> {
  code: number
  message: string
  data: T
  timestamp?: number
}

/** 后端抛错时的结构 */
export interface ApiErrorBody {
  code: number
  message: string
  data: null
}

// ---------------- 鉴权 ----------------

export interface AuthStatus {
  /** 是否已有可用账号 */
  initialized: boolean
  /** 当前请求是否已登录 */
  authenticated: boolean
  user?: string
}

export interface AuthUser {
  username: string
  role: string
  created?: number
}

/** 角色：主人 > 管理员 > 只读 */
export type RoleName = 'owner' | 'admin' | 'viewer'

export interface LoginResult {
  token: string
  user: AuthUser
  role: RoleName
  is_owner: boolean
  permissions: string[]
}

/** GET /auth/me：用户 + 角色 + 能力，前端据此隐藏入口 */
export interface AuthMe {
  user: AuthUser
  role: RoleName
  is_owner: boolean
  permissions: string[]
}

export interface UserRecord {
  username: string
  role: RoleName
  created: number
}

export interface UserList {
  items: UserRecord[]
  roles: RoleName[]
  capabilities: Record<string, RoleName[]>
}

// ---------------- 概览 ----------------

export interface AppInfo {
  name: string
  php: string
  swoole: string | null
  sodium: boolean
  redis: boolean
  debug: boolean
  store: 'redis' | 'file' | 'memory' | string
  timezone: string
}

export interface RuntimeInfo {
  started_at: number
  uptime: number
  pid: number
  memory: number
  peak: number
  server: string
}

export interface OverviewCounts {
  groups: number
  plugins: number
  plugins_on: number
  events: number
  messages: number
  protocols: number
}

export interface PhpInfo {
  version: string
  sapi: string
  extensions: string[]
  ini: {
    memory_limit: string
    max_execution_time: string
    'date.timezone': string
  }
}

/** 一条事件（Metrics::recordEvent 落库的字段） */
export interface TimelineEvent {
  post_type: string
  message_type: string
  notice_type: string
  sub_type: string
  self_id: string
  group_id: string
  user_id: string
  raw_message: string
  time: number
  protocol: string
  received: number
}

export interface Overview {
  app: AppInfo
  runtime: RuntimeInfo
  counts: OverviewCounts
  protocol: Record<string, ProtocolStatus>
  recent: TimelineEvent[]
  php: PhpInfo
}

// ---------------- 协议 ----------------

export interface ProtocolStatus {
  label: string
  enabled: boolean
  mode: string
  detail: {
    /** OneBot 正向 WS */
    forward_running?: boolean
    forward_url?: string
    reverse_sessions?: number
    reverse_path?: string
    /** Milky */
    api?: string
    status?: Record<string, unknown>
    ws_in?: number
    /** QQ 官方 */
    app_id?: string
  }
}

export type ProtocolName = 'onebot' | 'milky' | 'qqbot'

/** 协议详情（GET /protocols/{protocol}） */
export interface ProtocolDetail {
  name: ProtocolName
  status: ProtocolStatus
  config: Record<string, unknown>
  endpoints: Array<{ label: string; url: string; kind: string }>
  unsupported?: Array<{ action: string; reason: string }>
  supported?: Array<{ action: string }>
}

// ---------------- 配置 ----------------

/** 配置树：任意嵌套，叶子是标量或 null */
export type ConfigValue = string | number | boolean | null | ConfigValue[] | ConfigTree
export interface ConfigTree {
  [key: string]: ConfigValue
}

export interface SettingsData {
  config: ConfigTree
  warnings: string[]
  path: string
  writable: boolean
}

export interface SettingsUpdateResult {
  key: string
  value: ConfigValue
  backup: string | null
  warnings: string[]
}

// ---------------- 订阅 ----------------

export interface SubscriptionState {
  message: boolean
  notice: boolean
  request: boolean
  meta_event: boolean
  message_scope: 'group' | 'private' | 'all'
  notice_types: string[]
  ignore_self: boolean
  ignore_robots: boolean
  /** 机器人 ID 前缀，多个用英文逗号分隔（如 "3889,4000"） */
  robot_prefix: string
  /** 后端解析后的前缀列表（只读） */
  robot_prefixes?: string[]
  /** 扁平目录（兼容旧后端） */
  notice_catalog: Record<string, string>
  /** 分区目录（后端 v2 返回；缺失时前端退回扁平目录） */
  notice_groups?: NoticeGroup[]
  /** 各来源的条目数：builtin / plugin / custom */
  notice_stats?: Record<string, number>
  /** 全新部署时的默认勾选（= 全部目录项） */
  notice_defaults?: string[]
  /** 请求类型白名单（空 = 全收） */
  request_types: string[]
  /** 元事件类型白名单（空 = 全收） */
  meta_event_types: string[]
  request_catalog?: Record<string, string>
  meta_event_catalog?: Record<string, string>
  /** 按群覆盖：群号 -> 覆盖项 */
  group_overrides?: Record<string, GroupOverride>
}

/** 单个群的订阅覆盖（缺省字段跟随全局） */
export interface GroupOverride {
  /** 该群不接收任何事件 */
  mute: boolean
  /** 该群的消息是否接收 */
  message: boolean
  /** 非空 = 该群只收这些通知类型 */
  notice_types: string[]
}

export interface NoticeGroup {
  id?: string
  title: string
  types: Record<string, string>
  /** builtin = 内置清单，plugin = 插件 @subscribe 声明，custom = 面板里添加 */
  source?: 'builtin' | 'plugin' | 'custom'
}

export interface SubscriptionCatalogData {
  groups: NoticeGroup[]
  types: Record<string, string>
  stats: Record<string, number>
  defaults: string[]
}

export interface SubscriptionData {
  subscription: SubscriptionState
  configured_from: 'v1' | 'v2'
  /** 当前架构：webui-only 时不会接协议事件流 */
  arch?: 'swoole' | 'php' | 'webui'
  receives_events?: boolean
  arch_hint?: string | null
}

export interface SubscriptionExplain {
  why: string[]
  v1_keys: string[]
  v2_keys: string[]
  compatible: string
}

// ---------------- 群 ----------------

export interface Group {
  group_id: string
  name: string
  note: string
  tags: string[]
  status: boolean
  plugins_total: number
  plugins_on: number
  worker: Record<string, boolean | null>
  updated_at: number
}

export interface GroupList {
  items: Group[]
  total: number
}

// ---------------- 插件 ----------------

export interface PluginHook {
  type: string
  /** 中文类型：命令 / 通知 / 请求 */
  label?: string
  method: string
  /** 触发条件：命令正则，或通知/请求的事件类型 */
  trigger?: string
  match?: string
  /** group | private */
  scope?: string
  name?: string | null
  permission?: string
}

export interface Plugin {
  class: string
  short: string
  name: string
  description: string
  author: string
  version: string
  lastUpdate: string
  enabled: boolean
  methods: number
  hooks: PluginHook[]
}

export interface PluginList {
  items: Plugin[]
  total: number
}

/** 导入结果：装完立刻回读一次注册表，plugin 为空说明包放对了但没被识别 */
export interface PluginImportResult {
  source: 'zip' | 'git'
  /** 落盘的目录名，也是插件的短名 */
  name: string
  /** 相对 runtime/github 的安装目录 */
  path: string
  /** 解压/克隆出来的文件数，给用户一个「确实有东西」的凭证 */
  files: number
  /** 命中注册表时返回插件本身 */
  plugin: Plugin | null
  /** 非致命问题：缺 composer.json、没有 psr-4、没扫到类 */
  warnings: string[]
}

// ---------------- 日志 ----------------

export interface LogEntry {
  time: string
  level: string
  channel: string
  message: string
  context?: Record<string, unknown>
}

export interface LogTail {
  lines: LogEntry[]
  stats?: Record<string, number>
  file?: string
}

export interface LogDates {
  dates: string[]
  items?: string[]
}

export interface MessageRecord {
  id?: string
  group_id?: string
  user_id?: string
  raw_message?: string
  time?: number
  [key: string]: unknown
}

// ---------------- 运行时 ----------------

export interface RuntimeMode {
  id: string
  label: string
  description?: string
  [key: string]: unknown
}

export interface RuntimeData {
  current: {
    mode: string
    label: string
    degraded: boolean
    reason: string
    requested: string
    limitations: string[]
  }
  configured: string
  modes: RuntimeMode[]
  php: {
    version: string
    extensions: Record<string, boolean>
  }
}

// ---------------- 系统 ----------------

export interface HealthData {
  /** 程序版本，来自仓库根目录 VERSION */
  version?: string
  ok: boolean
  checks: Array<{ name: string; ok: boolean; detail: string }>
}

export interface DiskData {
  root: string
  total: number
  free: number
  used: number
  dirs?: Array<{ name: string; size: number; files: number }>
}

export interface RouteItem {
  method: string
  path: string
  name: string
}

/** 机器人账号（多账号）：同一协议可以挂多个号，各自一套地址与凭据 */
export interface BotAccount {
  id: string
  name: string
  protocol: string
  self_id: string
  enable: boolean
  api?: string
  forward_url?: string
  remark?: string
  /** 只写：提交时带上表示要改令牌，响应里永远不回显 */
  token?: string
  forward_token?: string
  token_set?: boolean
  forward_token_set?: boolean
  created_at?: number
  updated_at?: number
}

export interface BotAccountList {
  accounts: BotAccount[]
  protocols: Record<string, string>
  stats: { total: number; enabled: number; protocols: Record<string, number> }
  path: string
}

/** 第三方登录 */
export interface OAuthProvider {
  id: string
  label: string
  enabled: boolean
  bound: boolean
  bound_label: string
  bound_at: number
}

export interface OAuthProviders {
  providers: OAuthProvider[]
  callback: string
  redirect_uri_required: boolean
}
/** 定时任务 */
export interface ScheduleAction {
  type: 'message' | 'handler'
  target?: string
  target_type?: 'group' | 'private'
  text?: string
  handler?: string
  account?: string
}

export interface ScheduleJob {
  id: string
  name: string
  cron: string
  enabled: boolean
  action: ScheduleAction
  next_run_at?: number
  next_run_text?: string
  last_run_at?: number
  last_run_text?: string
  last_status?: string
  last_message?: string
  overdue?: boolean
}

export interface ScheduleData {
  items: ScheduleJob[]
  presets: Array<{ expression: string; label: string }>
  stats: { total: number; enabled: number; failed: number }
  path: string
  arch?: string
}

/** QQ 成员权限（机器人使用权限，与后台登录无关） */
export interface MemberPermission {
  qq: string
  level: string
  label: string
}

export interface PermissionsData {
  items: MemberPermission[]
  levels: Record<string, string>
  stats: { total: number; by_level: Record<string, number> }
}
