/**
 * Naive UI 2.45 适配器（CSS-in-JS，无需 import 样式文件）。
 *
 * 契约 → Naive 的胶水集中在：
 * - Root：n-config-provider + themeOverrides.common.primaryColor + darkTheme。
 * - n-data-table：columns.render 转调 #cell-<key>，分页默认关闭。
 * - n-form / n-form-item：prop → path，error → feedback + validation-status。
 * - n-modal preset="card"；n-dropdown :options；n-popover 宽度走 content-style。
 */
import { computed, defineComponent, h, ref, type PropType } from 'vue'
import {
  NAlert, NAvatar, NButton, NCard, NCheckbox, NConfigProvider, NDataTable, NDescriptions,
  NDescriptionsItem, NDivider, NDropdown, NEmpty, NForm, NFormItem, NInput, NInputNumber, NModal,
  NPopconfirm, NPopover, NProgress, NRadioButton, NRadioGroup, NSelect, NSkeleton, NSlider,
  NSwitch, NTag, NTooltip, darkTheme, dateZhCN, zhCN,
} from 'naive-ui'
import type { GlobalThemeOverrides } from 'naive-ui'
import { shift, uiTheme } from '../theme-bridge'
import type { UiAdapter, UiColumn, UiDropdownItem, UiOption, UiThemeTokens } from '../types'

/** 当前主题令牌：Root 读这里，applyTheme 直接写这里 */
const themeTokens = ref<UiThemeTokens>({ accent: uiTheme.accent, dark: uiTheme.dark })

/** 契约语义色 → Naive 配色名（ok/success、warn/warning 等别名全部兼容） */
function colorOf(type: string): string {
  switch (type) {
    case 'ok':
    case 'success': return 'success'
    case 'warn':
    case 'warning': return 'warning'
    case 'danger':
    case 'error': return 'error'
    case 'accent':
    case 'primary': return 'primary'
    case 'info': return 'info'
    default: return 'default'
  }
}

/** 按钮配色：语义名 → Naive ButtonType（danger 是 error，不是 default） */
function buttonType(type: string): string {
  switch (type) {
    case 'primary': return 'primary'
    case 'danger':
    case 'error': return 'error'
    case 'ok':
    case 'success': return 'success'
    case 'warn':
    case 'warning': return 'warning'
    case 'info': return 'info'
    default: return 'default'
  }
}

/** 尺寸：default -> medium */
function sizeOf(size: string | undefined): string {
  if (size === 'small') return 'small'
  if (size === 'large') return 'large'
  if (size === 'tiny') return 'tiny'
  return 'medium'
}

function widthOf(w: string | number | undefined, fallback: string): string {
  if (w === undefined || w === null || w === '') return fallback
  return typeof w === 'number' ? String(w) + 'px' : String(w)
}

/** 根 Provider：暗色主题 + 主色覆盖 + 中文语言包 */
const Root = defineComponent({
  name: 'UiNaiveRoot',
  setup(_props, { slots }) {
    const overrides = computed<GlobalThemeOverrides>(() => ({
      common: {
        primaryColor: themeTokens.value.accent,
        primaryColorHover: shift(themeTokens.value.accent, 0.12),
        primaryColorPressed: shift(themeTokens.value.accent, -0.12),
        primaryColorSuppl: shift(themeTokens.value.accent, 0.12),
        borderRadius: '6px',
      },
    }))
    return () =>
      h(NConfigProvider as any, {
        theme: themeTokens.value.dark ? darkTheme : null,
        themeOverrides: overrides.value,
        locale: zhCN,
        dateLocale: dateZhCN,
      }, { default: () => slots.default?.() })
  },
})

/** 卡片：header 插槽 + 右侧操作区，padding 翻译成 contentStyle */
const Card = defineComponent({
  name: 'UiNaiveCard', inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    padding: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    const contentStyle = computed(() => {
      if (props.padding === 'none') return { padding: '0' }
      if (props.padding === 'small') return { padding: '12px 14px' }
      return undefined
    })
    return () => {
      const children: Record<string, unknown> = { default: () => slots.default?.() }
      const rawHead = slots.header ?? slots.title
      const fallback = props.title || props.subtitle
        ? () => [
            props.title ? h('div', { class: 'ui-card-title' }, props.title) : null,
            props.subtitle ? h('div', { class: 'ui-card-subtitle' }, props.subtitle) : null,
          ]
        : undefined
      const head = rawHead ?? fallback
      if (head) {
        children.header = () => h('div', { class: 'ui-card-head' }, rawHead
          ? [h('div', { class: 'ui-card-title' }, rawHead()), props.subtitle
              ? h('div', { class: 'ui-card-subtitle' }, props.subtitle) : null]
          : head())
      }
      const extra = slots.extra ?? slots['header-extra']
      if (extra) children['header-extra'] = () => h('div', { class: 'ui-card-extra' }, extra())
      if (slots.footer) children.footer = slots.footer
      return h(NCard as any, {
        ...attrs,
        contentStyle: contentStyle.value,
      }, children as any)
    }
  },
})

/** 按钮：type 语义化，text/plain 映射到 Naive 的 text/quaternary */
const Button = defineComponent({
  name: 'UiNaiveButton', inheritAttrs: false,
  props: {
    type: { type: String, default: 'default' },
    size: { type: String, default: 'default' },
    plain: { type: Boolean, default: false },
    round: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots }) {
    return () => h(NButton as any, {
      ...attrs,
      type: buttonType(props.type),
      size: sizeOf(props.size),
      text: props.type === 'text',
      quaternary: props.plain,
      round: props.round || undefined,
      loading: props.loading,
      disabled: props.disabled,
    }, slots as any)
  },
})

/** 标签：effect 无等价物，dark -> strong，plain -> 透明底 + 保留边框 */
const Tag = defineComponent({
  name: 'UiNaiveTag', inheritAttrs: false,
  props: {
    type: { type: String, default: 'plain' },
    effect: { type: String, default: 'light' },
    size: { type: String, default: undefined },
    round: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots }) {
    return () => {
      const plainStyle = props.effect === 'plain'
        ? { background: 'transparent' }
        : undefined
      const userStyle = (attrs as any).style as Record<string, string> | undefined
      return h(NTag as any, {
        ...attrs,
        type: colorOf(props.type),
        size: props.size && props.size !== 'default' ? props.size : undefined,
        strong: props.effect === 'dark' || undefined,
        round: props.round || undefined,
        style: plainStyle ? { ...userStyle, ...plainStyle } : userStyle,
      }, slots as any)
    }
  },
})

/** 输入框：补 @enter / @clear，textarea 走 rows */
const Input = defineComponent({
  name: 'UiNaiveInput', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    type: { type: String, default: 'text' },
    rows: { type: [Number, String], default: undefined },
    showWordLimit: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'enter', 'clear'],
  setup(props, { attrs, slots, emit }) {
    const nativeType = (): 'text' | 'textarea' | 'password' => {
      if (props.type === 'textarea') return 'textarea'
      if (props.type === 'password') return 'password'
      return 'text'
    }
    return () => h(NInput as any, {
      ...attrs,
      type: nativeType(),
      value: props.modelValue as any,
      rows: props.rows,
      showCount: props.showWordLimit || (attrs as any).showCount,
      'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
      onKeyup: (e: KeyboardEvent) => {
        const own = (attrs as any).onKeyup
        if (typeof own === 'function') own(e)
        if (e.key === 'Enter') emit('enter')
      },
      onClear: () => emit('clear'),
    }, slots as any)
  },
})

/** 数字输入 */
const InputNumber = defineComponent({
  name: 'UiNaiveInputNumber', inheritAttrs: false,
  props: {
    modelValue: { type: Number, default: undefined },
    min: { type: [Number, String], default: undefined },
    max: { type: [Number, String], default: undefined },
    step: { type: [Number, String], default: undefined },
    precision: { type: Number, default: undefined },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(NInputNumber as any, {
      ...attrs,
      value: props.modelValue ?? null,
      min: props.min,
      max: props.max,
      step: props.step,
      precision: props.precision,
      'onUpdate:value': (v: number | null) => {
        emit('update:modelValue', v)
        emit('change', v)
      },
    })
  },
})

/** 下拉选择：options 直传（{label,value,disabled} 结构一致） */
const Select = defineComponent({
  name: 'UiNaiveSelect', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number, Array] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () => h(NSelect as any, {
      ...attrs,
      value: props.modelValue ?? null,
      options: props.options.map((o) => ({ label: o.label, value: o.value, disabled: o.disabled })),
      'onUpdate:value': (v: unknown) => {
        emit('update:modelValue', v)
        emit('change', v)
      },
    }, slots as any)
  },
})

/** 开关：activeText / inactiveText 映射到 Naive 的 checked / unchecked 插槽 */
const Switch = defineComponent({
  name: 'UiNaiveSwitch', inheritAttrs: false,
  props: {
    modelValue: { type: [Boolean, String, Number] as PropType<unknown>, default: false },
    activeText: { type: String, default: '' },
    inactiveText: { type: String, default: '' },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => {
      const children: Record<string, unknown> = {}
      if (props.activeText) children.checked = () => props.activeText
      if (props.inactiveText) children.unchecked = () => props.inactiveText
      return h(NSwitch as any, {
        ...attrs,
        value: props.modelValue,
        'onUpdate:value': (v: unknown) => {
          emit('update:modelValue', v)
          emit('change', v)
        },
      }, children as any)
    }
  },
})

/** 勾选框：文字走默认插槽 */
const Checkbox = defineComponent({
  name: 'UiNaiveCheckbox', inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () => h(NCheckbox as any, {
      ...attrs,
      checked: props.modelValue,
      'onUpdate:checked': (v: boolean) => {
        emit('update:modelValue', v)
        emit('change', v)
      },
    }, { default: slots.default ?? (() => props.label) })
  },
})

/** 单选组：options 转成 Naive 的 {label,value} */
const RadioGroup = defineComponent({
  name: 'UiNaiveRadioGroup', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(NRadioGroup as any, {
      ...attrs,
      value: props.modelValue,
      options: props.options.map((o) => ({ label: o.label, value: o.value, disabled: o.disabled })),
      'onUpdate:value': (v: unknown) => {
        emit('update:modelValue', v)
        emit('change', v)
      },
    })
  },
})

/** 分段控件：Naive 无 Segmented，用 RadioGroup + RadioButton 拼 */
const Segmented = defineComponent({
  name: 'UiNaiveSegmented', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    block: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(NRadioGroup as any, {
      ...attrs,
      size: (attrs as any).size ?? 'small',
      value: props.modelValue,
      style: props.block ? { display: 'flex', width: '100%' } : undefined,
      'onUpdate:value': (v: unknown) => {
        emit('update:modelValue', v)
        emit('change', v)
      },
    }, {
      default: () => props.options.map((o) => h(NRadioButton as any, {
        key: o.value,
        value: o.value,
        disabled: o.disabled,
        style: props.block ? { flex: 1 } : undefined,
      }, { default: () => o.label })),
    })
  },
})

/** 滑块：数组值自动开 range；showTooltip → tooltip */
const Slider = defineComponent({
  name: 'UiNaiveSlider', inheritAttrs: false,
  props: {
    modelValue: { type: [Number, Array] as PropType<unknown>, default: 0 },
    min: { type: Number, default: undefined },
    max: { type: Number, default: undefined },
    step: { type: Number, default: undefined },
    disabled: { type: Boolean, default: false },
    showTooltip: { type: Boolean, default: true },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(NSlider as any, {
      ...attrs,
      value: props.modelValue,
      range: Array.isArray(props.modelValue) || Boolean((attrs as any).range),
      min: props.min ?? 0,
      max: props.max ?? 100,
      step: props.step,
      disabled: props.disabled,
      tooltip: props.showTooltip,
      'onUpdate:value': (v: unknown) => {
        emit('update:modelValue', v)
        emit('change', v)
      },
    })
  },
})

/** 表单：暴露 validate() -> Promise<boolean>；Naive 只有 restoreValidation，resetFields 自行快照回填 */
const Form = defineComponent({
  name: 'UiNaiveForm', inheritAttrs: false,
  props: {
    model: { type: Object as PropType<Record<string, unknown>>, default: undefined },
    rules: { type: Object as PropType<Record<string, unknown>>, default: undefined },
    labelWidth: { type: [String, Number], default: undefined },
    labelPosition: { type: String, default: undefined },
    inline: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots, expose }) {
    const inner = ref<any>()
    let snapshot: Record<string, unknown> | null = null
    const capture = (): void => {
      if (!snapshot && props.model) {
        try {
          snapshot = JSON.parse(JSON.stringify(props.model)) as Record<string, unknown>
        } catch {
          snapshot = { ...props.model }
        }
      }
    }
    expose({
      validate: async (): Promise<boolean> => {
        capture()
        try {
          await inner.value?.validate()
          return true
        } catch {
          return false
        }
      },
      resetFields: (): void => {
        capture()
        const model = props.model
        if (model && snapshot) {
          for (const key of Object.keys(snapshot)) model[key] = snapshot[key]
        }
        inner.value?.restoreValidation?.()
      },
      clearValidate: (): void => {
        inner.value?.restoreValidation?.()
      },
    })
    return () => {
      capture()
      return h(NForm as any, {
        ...attrs,
        ref: inner,
        model: props.model,
        rules: props.rules,
        labelWidth: props.labelWidth,
        labelPlacement: props.labelPosition === 'top' ? 'top' : 'left',
        inline: props.inline,
        disabled: props.disabled,
      }, slots as any)
    }
  },
})

/** 表单项：契约里的 prop 翻译成 Naive 的 path，error 翻译成 feedback */
const FormItem = defineComponent({
  name: 'UiNaiveFormItem', inheritAttrs: false,
  props: {
    label: { type: String, default: '' },
    prop: { type: String, default: undefined },
    path: { type: String, default: undefined },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
    labelWidth: { type: [String, Number], default: undefined },
    rules: { type: [Object, Array] as PropType<unknown>, default: undefined },
  },
  setup(props, { attrs, slots }) {
    return () => h(NFormItem as any, {
      ...attrs,
      label: props.label || undefined,
      path: props.path ?? props.prop ?? (attrs as any).path,
      required: props.required || undefined,
      rule: props.rules ?? (attrs as any).rule,
      labelWidth: props.labelWidth,
      validationStatus: props.error ? 'error' : (attrs as any).validationStatus,
      feedback: props.error || undefined,
    }, {
      default: slots.default,
      ...(slots.label ? { label: slots.label } : {}),
      ...(slots.error ? { feedback: slots.error } : {}),
    })
  },
})

/** 表格：Naive DataTable 没有单元格插槽，columns.render 里转调 #cell-<key> */
const Table = defineComponent({
  name: 'UiNaiveTable', inheritAttrs: false,
  props: {
    data: { type: Array as PropType<Record<string, unknown>[]>, default: () => [] },
    columns: { type: Array as PropType<UiColumn[]>, default: () => [] },
    rowKey: { type: [String, Function] as PropType<string | ((row: Record<string, unknown>, index: number) => string)>, default: undefined },
    emptyText: { type: String, default: undefined },
    stripe: { type: Boolean, default: false },
    border: { type: Boolean, default: false },
    size: { type: String, default: undefined },
    height: { type: [String, Number], default: undefined },
    maxHeight: { type: [String, Number], default: undefined },
  },
  emits: ['row-click'],
  setup(props, { attrs, slots, emit }) {
    const defaultRowKey = (row: Record<string, unknown>, index: number): string =>
      String(row.key ?? row.id ?? index)
    // Naive 的 DataTable 只接受函数形式的 rowKey（传字符串会运行时报错）
    const rowKeyFn = (row: Record<string, unknown>, index: number): string => {
      if (typeof props.rowKey === 'function') return String(props.rowKey(row, index))
      if (typeof props.rowKey === 'string' && row[props.rowKey] != null) return String(row[props.rowKey])
      return defaultRowKey(row, index)
    }
    return () => {
      const columns = props.columns.map((col) => {
        const cell = slots['cell-' + col.key]
        return {
          key: col.key,
          title: col.title,
          width: col.width,
          minWidth: col.minWidth,
          align: col.align,
          ellipsis: col.ellipsis ? { tooltip: true } : undefined,
          render: (row: Record<string, unknown>, index: number) => {
            const value = row[col.key]
            return cell ? cell({ row, index, value }) : (value as any)
          },
        }
      })
      const empty = slots.empty ?? (() => h(NEmpty as any, {
        description: props.emptyText ?? '暂无数据',
        size: 'small',
      }))
      const table = h(NDataTable as any, {
        ...attrs,
        data: props.data,
        columns: columns as any,
        rowKey: rowKeyFn,
        striped: props.stripe || undefined,
        bordered: (attrs as any).bordered ?? props.border,
        size: sizeOf(props.size),
        pagination: (attrs as any).pagination ?? false,
        maxHeight: props.maxHeight ?? props.height,
        rowProps: (row: Record<string, unknown>, index: number) => ({
          onClick: () => emit('row-click', row, index),
        }),
      }, { empty })
      return slots.append
        ? h('div', { class: 'ui-table-wrap' }, [table, h('div', { class: 'ui-table-append' }, slots.append())])
        : table
    }
  },
})

/** 描述列表 */
const Descriptions = defineComponent({
  name: 'UiNaiveDescriptions', inheritAttrs: false,
  props: {
    items: { type: Array as PropType<{ label: string; value: unknown; span?: number }[]>, default: () => [] },
    column: { type: Number, default: 3 },
    border: { type: Boolean, default: false },
    direction: { type: String, default: 'horizontal' },
    size: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    return () => h(NDescriptions as any, {
      ...attrs,
      column: props.column,
      size: props.size === 'default' ? 'medium' : (props.size ?? 'medium'),
      bordered: props.border || Boolean((attrs as any).bordered),
      labelPlacement: props.direction === 'vertical' ? 'top' : ((attrs as any).labelPlacement ?? 'left'),
    }, {
      default: () => slots.default
        ? slots.default()
        : props.items.map((item, index) => h(NDescriptionsItem as any, {
            key: index,
            label: item.label,
            span: item.span,
          }, { default: () => item.value as any })),
    })
  },
})

/** 弹窗：NModal preset=card，footer 插槽透传，after-leave -> closed */
const Dialog = defineComponent({
  name: 'UiNaiveDialog', inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    width: { type: [String, Number], default: 480 },
    closeOnClickModal: { type: Boolean, default: undefined },
  },
  emits: ['update:modelValue', 'closed'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const children: Record<string, unknown> = {}
      if (slots.default) children.default = slots.default
      if (slots.footer) children.footer = slots.footer
      const rawStyle = (attrs as any).style
      const baseStyle = rawStyle && typeof rawStyle === 'object' ? rawStyle : undefined
      const widthPx = widthOf(props.width, '480px')
      const style = typeof rawStyle === 'string'
        ? rawStyle + ';width:' + widthPx
        : { ...(baseStyle ?? {}), width: widthPx }
      return h(NModal as any, {
        ...attrs,
        preset: 'card',
        show: props.modelValue,
        title: props.title,
        maskClosable: props.closeOnClickModal ?? (attrs as any).maskClosable ?? true,
        style,
        'onUpdate:show': (v: boolean) => emit('update:modelValue', v),
        onAfterLeave: () => emit('closed'),
      }, children as any)
    }
  },
})

/** 空状态 */
const Empty = defineComponent({
  name: 'UiNaiveEmpty', inheritAttrs: false,
  props: {
    description: { type: String, default: '' },
    size: { type: String, default: undefined },
    image: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    return () => h(NEmpty as any, {
      ...attrs,
      description: props.description || undefined,
      size: props.size === 'default' ? 'medium' : props.size,
    }, slots as any)
  },
})

/** 骨架屏：rows → n-skeleton repeat */
const Skeleton = defineComponent({
  name: 'UiNaiveSkeleton', inheritAttrs: false,
  props: {
    rows: { type: Number, default: 5 },
    animated: { type: Boolean, default: true },
    loading: { type: Boolean, default: true },
  },
  setup(props, { attrs, slots }) {
    return () => {
      if (!props.loading && slots.default) return slots.default()
      const cls = (attrs as any).class
      return h('div', { ...attrs, class: ['ui-skeleton', cls] }, [
        h(NSkeleton as any, {
          text: true,
          round: true,
          animated: props.animated,
          repeat: Math.max(1, props.rows),
          height: 14,
          style: { marginBottom: '8px' },
        }),
      ])
    }
  },
})

const PROGRESS_STATUS: Record<string, string> = {
  ok: 'success', success: 'success',
  warn: 'warning', warning: 'warning',
  danger: 'error', error: 'error',
  info: 'info', default: 'default',
}

/** 进度条：status 语义别名 → Naive status；showText → showIndicator */
const Progress = defineComponent({
  name: 'UiNaiveProgress', inheritAttrs: false,
  props: {
    percentage: { type: Number, default: 0 },
    status: { type: String, default: undefined },
    strokeWidth: { type: Number, default: undefined },
    type: { type: String, default: 'line' },
    showText: { type: Boolean, default: true },
    color: { type: String, default: undefined },
  },
  setup(props, { attrs }) {
    return () => h(NProgress as any, {
      ...attrs,
      type: props.type === 'circle' ? 'circle' : 'line',
      percentage: Math.max(0, Math.min(100, props.percentage)),
      status: props.status ? (PROGRESS_STATUS[props.status] ?? 'default') : 'default',
      showIndicator: props.showText,
      strokeWidth: props.strokeWidth,
      color: props.color,
    })
  },
})

/** 提示条 */
const Alert = defineComponent({
  name: 'UiNaiveAlert', inheritAttrs: false,
  props: {
    type: { type: String, default: 'info' },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    showIcon: { type: Boolean, default: true },
    closable: { type: Boolean, default: false },
  },
  emits: ['close'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const userClose = (attrs as any).onClose
      return h(NAlert as any, {
        ...attrs,
        type: colorOf(props.type),
        title: props.title || undefined,
        showIcon: props.showIcon,
        closable: props.closable,
        onClose: () => {
          if (typeof userClose === 'function') userClose()
          emit('close')
        },
      }, {
        default: slots.default ?? (props.description ? (() => props.description) : undefined),
      })
    }
  },
})

/** 气泡确认 */
const Popconfirm = defineComponent({
  name: 'UiNaivePopconfirm', inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    confirmText: { type: String, default: '确定' },
    cancelText: { type: String, default: '取消' },
    disabled: { type: Boolean, default: false },
  },
  emits: ['confirm', 'cancel'],
  setup(props, { attrs, slots, emit }) {
    return () => h(NPopconfirm as any, {
      ...attrs,
      positiveText: props.confirmText,
      negativeText: props.cancelText,
      disabled: props.disabled,
      onPositiveClick: () => emit('confirm'),
      onNegativeClick: () => emit('cancel'),
    }, {
      trigger: slots.default,
      default: () => props.title,
    })
  },
})

/** 下拉菜单：divided 翻译成 Naive 的 divider 选项 */
const Dropdown = defineComponent({
  name: 'UiNaiveDropdown', inheritAttrs: false,
  props: {
    items: { type: Array as PropType<UiDropdownItem[]>, default: () => [] },
    trigger: { type: String, default: 'click' },
    placement: { type: String, default: 'bottom-end' },
    disabled: { type: Boolean, default: false },
  },
  emits: ['select'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const options: Record<string, unknown>[] = []
      for (const item of props.items) {
        if (item.divided) options.push({ type: 'divider', key: 'divider-' + item.key })
        options.push({
          key: item.key,
          label: item.label,
          disabled: item.disabled,
          props: item.danger ? { style: { color: 'var(--n-error-color)' } } : undefined,
        })
      }
      return h(NDropdown as any, {
        ...attrs,
        trigger: props.trigger,
        placement: props.placement,
        disabled: props.disabled,
        options,
        onSelect: (key: string) => emit('select', key),
      }, { default: slots.default })
    }
  },
})

/** 气泡卡片：#default 触发器，#content 内容；宽度走 contentStyle */
const Popover = defineComponent({
  name: 'UiNaivePopover', inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    width: { type: [String, Number], default: 240 },
    placement: { type: String, default: 'bottom' },
    trigger: { type: String, default: 'click' },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, slots, emit }) {
    return () => h(NPopover as any, {
      ...attrs,
      show: props.disabled ? false : props.modelValue,
      placement: props.placement,
      trigger: props.trigger,
      contentStyle: { width: widthOf(props.width, '240px') },
      'onUpdate:show': (v: boolean) => {
        if (!props.disabled) emit('update:modelValue', v)
      },
    }, {
      trigger: slots.default,
      default: () => [
        props.title ? h('div', { class: 'ui-popover-title' }, props.title) : null,
        slots.content?.(),
      ],
    })
  },
})

/** 提示气泡：默认插槽当触发器，内容来自 content 或 #content */
const Tooltip = defineComponent({
  name: 'UiNaiveTooltip', inheritAttrs: false,
  props: {
    content: { type: String, default: '' },
    placement: { type: String, default: 'top' },
    trigger: { type: String, default: 'hover' },
    disabled: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots }) {
    return () => h(NTooltip as any, {
      ...attrs,
      placement: props.placement,
      trigger: props.trigger,
      disabled: props.disabled,
    }, {
      trigger: slots.default,
      default: slots.content ?? (props.content ? (() => props.content) : undefined),
    })
  },
})

/** 头像：契约 shape circle|square → Naive circle/round 布尔 */
const Avatar = defineComponent({
  name: 'UiNaiveAvatar', inheritAttrs: false,
  props: {
    size: { type: [Number, String], default: undefined },
    src: { type: String, default: '' },
    shape: { type: String, default: 'circle' },
  },
  setup(props, { attrs, slots }) {
    return () => h(NAvatar as any, {
      ...attrs,
      size: props.size,
      src: props.src || undefined,
      circle: props.shape !== 'square',
      round: false,
    }, slots as any)
  },
})

/** 图标：自绘 span，插槽里放 svg */
const Icon = defineComponent({
  name: 'UiNaiveIcon', inheritAttrs: false,
  props: {
    size: { type: [Number, String], default: undefined },
    color: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    const userStyle = (attrs as any).style as Record<string, string> | undefined
    return () => {
      const size = typeof props.size === 'number' ? props.size + 'px' : props.size
      const style: Record<string, string> = {}
      if (size) style.fontSize = size
      if (props.color) style.color = props.color
      return h('span', {
        ...attrs,
        class: ['ui-icon', (attrs as any).class],
        style: { ...style, ...(typeof userStyle === 'object' ? userStyle : {}) },
      }, slots.default?.())
    }
  },
})

const adapter: UiAdapter = {
  id: 'naive',
  name: 'Naive UI',
  Root,
  applyTheme(tokens: UiThemeTokens) {
    themeTokens.value = { accent: tokens.accent, dark: tokens.dark }
  },
  components: {
    Card,
    Button,
    Tag,
    Input,
    InputNumber,
    Select,
    Switch,
    Checkbox,
    RadioGroup,
    Segmented,
    Slider,
    Form,
    FormItem,
    Table,
    Descriptions,
    DescriptionItem: NDescriptionsItem as any,
    Dialog,
    Empty,
    Skeleton,
    Progress,
    Alert,
    Divider: NDivider as any,
    Tooltip,
    Popover,
    Dropdown,
    Popconfirm,
    Avatar,
    Icon,
  },
}

export default adapter
