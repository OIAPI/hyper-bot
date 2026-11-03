import type { App, Component } from 'vue'

/** 表格列：不同库由适配器翻译成各自的列定义 */
export interface UiColumn {
  key: string
  title: string
  width?: number | string
  minWidth?: number | string
  align?: 'left' | 'center' | 'right'
  ellipsis?: boolean
}

export interface UiOption {
  label: string
  value: string | number
  disabled?: boolean
}

export interface UiDropdownItem {
  key: string
  label: string
  danger?: boolean
  divided?: boolean
  disabled?: boolean
}

/**
 * 框架无关的控件清单。视图只认这些名字，具体渲染由当前框架的适配器提供。
 * 新增一套框架 = 新增 adapters/xxx.ts + 在 registry 注册，视图零改动。
 */
export type UiPart =
  | 'Card' | 'Button' | 'Tag' | 'Input' | 'InputNumber' | 'Select' | 'Switch'
  | 'Checkbox' | 'RadioGroup' | 'Segmented' | 'Slider' | 'Form' | 'FormItem' | 'Table'
  | 'Descriptions' | 'DescriptionItem' | 'Dialog' | 'Empty' | 'Skeleton'
  | 'Progress' | 'Alert' | 'Divider' | 'Tooltip' | 'Popover' | 'Dropdown'
  | 'Popconfirm' | 'Avatar' | 'Icon'

export interface UiThemeTokens {
  accent: string
  dark: boolean
}

export interface UiAdapter {
  id: string
  name: string
  /** 库级主题 Provider（a-config-provider / n-config-provider 等） */
  Root?: Component
  components: Partial<Record<UiPart, Component>>
  /** app 级安装（Vuetify 这类必须 app.use 的库） */
  install?: (app: App) => void
  /** 主题令牌变化时回调 */
  applyTheme?: (tokens: UiThemeTokens) => void
}
