import { reactive } from 'vue'

export const uiTheme = reactive({
  accent: '#2563eb',
  dark: false,
  hue: 212,
  sat: 82,
  light: 52,
})

export function hslToHex(h: number, s: number, l: number): string {
  const sn = Math.min(100, Math.max(0, s)) / 100
  const ln = Math.min(100, Math.max(0, l)) / 100
  const k = (n: number) => (n + h / 30) % 12
  const a = sn * Math.min(ln, 1 - ln)
  const f = (n: number) => {
    const v = ln - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)))
    return Math.round(255 * v).toString(16).padStart(2, '0')
  }
  return '#' + f(0) + f(8) + f(4)
}

export function hexToRgb(hex: string): [number, number, number] {
  let h = hex.replace('#', '').trim()
  if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]
  const n = Number.parseInt(h, 16)
  if (!Number.isFinite(n)) return [37, 99, 235]
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

export function rgba(hex: string, alpha: number): string {
  const [r, g, b] = hexToRgb(hex)
  return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')'
}

export function shift(hex: string, amount: number): string {
  const [r, g, b] = hexToRgb(hex)
  const f = (v: number) =>
    Math.round(amount >= 0 ? v + (255 - v) * amount : v * (1 + amount))
      .toString(16)
      .padStart(2, '0')
  return '#' + f(r) + f(g) + f(b)
}
