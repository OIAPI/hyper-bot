/** 格式化工具。存在的原因：同一个「运行时长」在 4 个页面都要显示，不能各写一遍。 */

/** 秒 → 「3 天 4 小时」，用于运行时长 */
export function formatUptime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) return '—'
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = Math.floor(seconds % 60)

  const parts: string[] = []
  if (d > 0) parts.push(d + ' 天')
  if (h > 0) parts.push(h + ' 小时')
  if (d === 0 && m > 0) parts.push(m + ' 分')
  if (d === 0 && h === 0 && s > 0) parts.push(s + ' 秒')
  return parts.length ? parts.join(' ') : '刚刚'
}

/** 字节 → 人类可读 */
export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let v = bytes
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return (v >= 100 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i]
}

/** 时间戳（秒）→ 本地时间字符串 */
export function formatTime(ts: number, withDate = true): string {
  if (!Number.isFinite(ts) || ts <= 0) return '—'
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  const time = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
  if (!withDate) return time
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${time}`
}

/** 相对时间：「3 分钟前」 */
export function formatRelative(ts: number): string {
  if (!Number.isFinite(ts) || ts <= 0) return '—'
  const diff = Math.floor(Date.now() / 1000) - ts
  if (diff < 10) return '刚刚'
  if (diff < 60) return diff + ' 秒前'
  if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前'
  if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前'
  if (diff < 2592000) return Math.floor(diff / 86400) + ' 天前'
  return formatTime(ts)
}

/** 配置值 → 展示字符串 */
export function displayValue(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'true' : 'false'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

/** 判断是否是可以继续下钻的配置节点 */
export function isConfigNode(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v)
}
