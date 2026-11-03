/**
 * Arco Design Vue 适配器（@arco-design/web-vue 2.x）。
 *
 * 与元素库不一致、需要 glue 的地方：
 * - 主题：Arco 没有 React 式 Provider，主色是 body 上的 CSS 变量
 *   （--primary-N / --arcoblue-N 是 "r,g,b" 三元组），applyTheme 直接写变量，
 *   暗色切换 body[arco-theme="dark"]。
 * - Card：Arco 的 header 只认 title/extra；中立契约要 #header/#title +
 *   标题区 class，这里把标题区塞进 a-card 的 title 插槽。
 * - Table：Arco 用 column.slotName + 表级同名插槽，不是 #cell-xxx 自动透传，
 *   这里为每列生成 slotName，并把 {record,rowIndex} 归一化成 {row,index,value}。
 * - Form：Arco 的 validate() 以 errors 对象 resolve（不 reject），
 *   这里统一成 Promise<boolean>；FormItem 的字段名是 field 不是 prop。
 * - Dialog：Arco 用 v-model:visible，且 footer 只在 footer 属性为真时渲染；
 *   中立契约没有默认按钮，所以仅当调用方给 #footer 时才打开 footer。
 * - Segmented：Arco 没有分段控件，用 RadioGroup(type="button") 做最小 shim。
 * - Dropdown：Arco 没有 items prop，用 Doption 拼 #content；divided/danger
 *   用分隔线与内联色模拟。
 * - Popover/Popconfirm：Arco 的 Popover 没有 disabled，Popconfirm 也没有；
 *   禁用时用 trigger=[] / 受控 popupVisible=false 阻止弹出。
 * - Empty/Skeleton/Progress/Alert：属性名与 type 别名在这里归一化。
 * - Icon：只当图标容器用（Element Plus 的 SVG 自带 1em 尺寸/currentColor），
 *   用 span 自绘，size/color 走内联样式。
 */
import { computed, defineComponent, h, ref, type Component, type PropType } from 'vue'
import {
  Alert as AAlert,
  Avatar as AAvatar,
  Button as AButton,
  Card as ACard,
  Checkbox as ACheckbox,
  ConfigProvider as AConfigProvider,
  Descriptions as ADescriptions,
  DescriptionsItem as ADescriptionsItem,
  Divider as ADivider,
  Doption,
  Dropdown as ADropdown,
  Empty as AEmpty,
  Form as AForm,
  FormItem as AFormItem,
  Input as AInput,
  InputNumber as AInputNumber,
  Modal as AModal,
  Popconfirm as APopconfirm,
  Popover as APopover,
  Progress as AProgress,
  RadioGroup as ARadioGroup,
  Select as ASelect,
  Skeleton as ASkeleton,
  SkeletonLine,
  Slider as ASlider,
  Switch as ASwitch,
  Table as ATable,
  Tag as ATag,
  Textarea as ATextarea,
  Tooltip as ATooltip,
} from '@arco-design/web-vue'
import '@arco-design/web-vue/dist/arco.css'
import type { UiAdapter, UiColumn, UiDropdownItem, UiOption, UiThemeTokens } from '../types'

/* ------------------------------------------------------------------ *
 * 主题
 * ------------------------------------------------------------------ */
function channels(hex: string): [number, number, number] | null {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim())
  if (!m) return null
  let raw = m[1]
  if (raw.length === 3) raw = raw[0] + raw[0] + raw[1] + raw[1] + raw[2] + raw[2]
  return [parseInt(raw.slice(0, 2), 16), parseInt(raw.slice(2, 4), 16), parseInt(raw.slice(4, 6), 16)]
}

function clamp(v: number): number {
  return Math.max(0, Math.min(255, Math.round(v)))
}

function mix(a: [number, number, number], b: [number, number, number], w: number): [number, number, number] {
  return [clamp(a[0] + (b[0] - a[0]) * w), clamp(a[1] + (b[1] - a[1]) * w), clamp(a[2] + (b[2] - a[2]) * w)]
}

function applyTheme(tokens: UiThemeTokens): void {
  if (typeof document === 'undefined') return
  const base = channels(tokens.accent) ?? [22, 93, 255]
  const white: [number, number, number] = [255, 255, 255]
  const black: [number, number, number] = [0, 0, 0]
  // 1-5 是浅色底，6 是主色，7-10 是 hover/active 的深色。
  // 暗色主题下方向反过来（暗底 + 更亮的主色阶梯）。
  const lightRatios = [0.88, 0.7, 0.5, 0.3, 0.14]
  const scale: string[] = []
  for (let i = 0; i < 5; i++) {
    scale.push(mix(base, tokens.dark ? black : white, lightRatios[i]).join(','))
  }
  scale.push(base.join(','))
  for (let i = 1; i <= 4; i++) {
    scale.push(mix(base, tokens.dark ? white : black, i * 0.12).join(','))
  }

  // arco.css 里 --primary-* / --link-* 只是 var(--arcoblue-*)，且 dark 规则
  // 在 body[arco-theme=dark] 上重新声明；内联变量优先级更高，所以三组都写。
  const roots = [document.documentElement, document.body].filter(Boolean) as HTMLElement[]
  for (const el of roots) {
    for (let i = 0; i < scale.length; i++) {
      const n = i + 1
      el.style.setProperty('--primary-' + n, scale[i])
      el.style.setProperty('--link-' + n, scale[i])
      el.style.setProperty('--arcoblue-' + n, scale[i])
    }
  }
  if (tokens.dark) document.body.setAttribute('arco-theme', 'dark')
  else document.body.removeAttribute('arco-theme')
}

/* ------------------------------------------------------------------ *
 * 通用映射
 * ------------------------------------------------------------------ */
function px(v: number | string): string {
  if (typeof v === 'number') return v + 'px'
  return /^\d+(\.\d+)?$/.test(v) ? v + 'px' : v
}

function numeric(v: number | string | undefined): number | undefined {
  if (v === undefined || v === '') return undefined
  const n = typeof v === 'number' ? v : Number(v)
  return Number.isFinite(n) ? n : undefined
}

/** Arco 的 position 是 tl/tr/bl/br 这类缩写 */
function mapPlacement(p?: string): string | undefined {
  if (!p) return undefined
  const map: Record<string, string> = {
    'top-start': 'tl',
    'top-end': 'tr',
    'bottom-start': 'bl',
    'bottom-end': 'br',
    'left-start': 'lt',
    'left-end': 'lb',
    'right-start': 'rt',
    'right-end': 'rb',
  }
  return map[p] ?? p
}

function mapSize(size?: string): string | undefined {
  if (!size) return undefined
  return size === 'default' ? 'medium' : size
}

/** 中立按钮 type/plain → arco 的 type + status */
function buttonPreset(type?: string, plain?: boolean): { type: string; status?: string } {
  if (type === 'text' || type === 'link') return { type: 'text' }
  if (type === 'primary') return plain ? { type: 'outline' } : { type: 'primary' }
  if (type === 'success' || type === 'warning' || type === 'danger') {
    return plain ? { type: 'outline', status: type } : { type: 'primary', status: type }
  }
  return plain ? { type: 'outline' } : { type: 'secondary' }
}

const TAG_FILL: Record<string, string> = {
  primary: 'arcoblue',
  accent: 'arcoblue',
  success: 'green',
  ok: 'green',
  warning: 'orange',
  warn: 'orange',
  danger: 'red',
  error: 'red',
  info: 'gray',
  neutral: 'gray',
}

const TAG_PLAIN_COLOR: Record<string, string> = {
  green: 'rgb(var(--success-6))',
  orange: 'rgb(var(--warning-6))',
  red: 'rgb(var(--danger-6))',
  arcoblue: 'rgb(var(--primary-6))',
  gray: 'var(--color-text-3)',
}

const PROGRESS_STATUS: Record<string, string> = {
  ok: 'success',
  success: 'success',
  warn: 'warning',
  warning: 'warning',
  danger: 'danger',
  error: 'danger',
  exception: 'danger',
  normal: 'normal',
}

const ALERT_TYPE: Record<string, string> = {
  primary: 'info',
  info: 'info',
  ok: 'success',
  success: 'success',
  warn: 'warning',
  warning: 'warning',
  danger: 'error',
  error: 'error',
  normal: 'normal',
}

function mergeStyle(style: unknown, extra?: Record<string, unknown>): unknown {
  if (!extra) return style
  return [style, extra]
}

/* ------------------------------------------------------------------ *
 * 组件
 * ------------------------------------------------------------------ */

/**
 * 适配层需要的一些「去默认感」样式：Arco 没有全局 token 文件，
 * 这些规则用内联 <style> 随 Root 注入（与 antd 适配器同一做法）。
 */
const SHIM_CSS = [
  '.ui-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; width: 100%; min-width: 0; font-size: 13.5px; }',
  '.ui-card-head-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }',
  '.ui-card-title { font-size: 13.5px; font-weight: 650; line-height: 1.45; color: var(--text); }',
  '.ui-card-subtitle { font-size: 11.5px; line-height: 1.4; color: var(--text-faint); }',
  '.ui-card-extra { display: inline-flex; align-items: center; gap: 8px; flex: none; }',
  '.arco-card { border-radius: var(--radius-lg); }',
  '.arco-card-bordered { border-color: var(--border-soft); }',
  '.arco-card-header-title { font-size: 13.5px; font-weight: 600; }',
  '.arco-btn { border-radius: var(--radius-btn, var(--radius-sm)); font-weight: 500; }',
  '.arco-input-wrapper, .arco-textarea-wrapper, .arco-select-view { border-radius: var(--radius-sm); }',
  '.arco-tag { border-radius: var(--radius-xs); font-weight: 500; }',
  '.arco-table { font-size: 12.8px; }',
  '.arco-table-th { font-size: 11.5px; font-weight: 560; color: var(--text-faint); }',
  '.ui-icon { display: inline-flex; align-items: center; justify-content: center; line-height: 1; vertical-align: middle; }',
].join('\n')

const Root = defineComponent({
  name: 'UiArcoRoot',
  inheritAttrs: false,
  setup(_props, { slots }) {
    return () => [
      h('style', { 'data-ui-shim': 'arco' }, SHIM_CSS),
      h(AConfigProvider as any, null, { default: () => slots.default?.() }),
    ]
  },
})

const Card = defineComponent({
  name: 'UiArcoCard',
  inheritAttrs: false,
  props: {
    title: String,
    subtitle: String,
    padding: String,
    bordered: { type: Boolean, default: true },
  },
  setup(props, { attrs, slots }) {
    return () => {
      const children: Record<string, any> = {}
      const hasHead = Boolean(props.title || props.subtitle || slots.title || slots.header || slots.extra)
      if (hasHead) {
        // #header 与 #title 都当标题区；#header 优先（老页面写法）
        const rawHead = slots.header ?? slots.title
        const main = rawHead
          ? [
              h('div', { class: 'ui-card-title' }, rawHead()),
              props.subtitle ? h('div', { class: 'ui-card-subtitle' }, props.subtitle) : null,
            ]
          : [
              props.title ? h('div', { class: 'ui-card-title' }, props.title) : null,
              props.subtitle ? h('div', { class: 'ui-card-subtitle' }, props.subtitle) : null,
            ]
        children.title = () =>
          h('div', { class: 'ui-card-head' }, [
            h('div', { class: 'ui-card-head-main' }, main),
            slots.extra ? h('div', { class: 'ui-card-extra' }, slots.extra()) : null,
          ])
      }
      if (slots.default) children.default = () => slots.default!()
      const bodyStyle =
        props.padding === 'none' ? { padding: '0' } : props.padding === 'small' ? { padding: '12px 14px' } : undefined
      return h(
        ACard as any,
        { ...attrs, bordered: props.bordered, bodyStyle, headerStyle: { height: 'auto' } },
        children,
      )
    }
  },
})

const Button = defineComponent({
  name: 'UiArcoButton',
  inheritAttrs: false,
  props: {
    type: String,
    size: String,
    plain: Boolean,
    round: Boolean,
    circle: Boolean,
    disabled: Boolean,
    loading: Boolean,
    block: Boolean,
  },
  setup(props, { attrs, slots }) {
    return () =>
      h(
        AButton as any,
        {
          size: mapSize(props.size),
          shape: props.circle ? 'circle' : props.round ? 'round' : undefined,
          loading: props.loading,
          disabled: props.disabled,
          long: props.block,
          ...buttonPreset(props.type, props.plain),
          ...attrs,
        },
        slots,
      )
  },
})

const Tag = defineComponent({
  name: 'UiArcoTag',
  inheritAttrs: false,
  props: {
    type: String,
    size: String,
    effect: String,
    round: Boolean,
    closable: Boolean,
  },
  emits: ['close'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const plain = props.type === 'plain' || props.effect === 'plain'
      const fill = props.type ? TAG_FILL[props.type] : undefined
      const style: Record<string, unknown> = {}
      if (props.round) style.borderRadius = '999px'
      if (plain) {
        const color = (fill && TAG_PLAIN_COLOR[fill]) || TAG_PLAIN_COLOR.gray
        style.color = color
        style.borderColor = color
        style.backgroundColor = 'transparent'
      }
      return h(
        ATag as any,
        {
          ...attrs,
          size: mapSize(props.size),
          color: plain ? undefined : fill,
          bordered: plain ? true : undefined,
          closable: props.closable,
          style: mergeStyle((attrs as any).style, style),
          onClose: (e: Event) => emit('close', e),
        },
        slots,
      )
    }
  },
})

const Input = defineComponent({
  name: 'UiArcoInput',
  inheritAttrs: false,
  props: {
    modelValue: [String, Number] as PropType<string | number | undefined>,
    type: String,
    textarea: Boolean,
    rows: [Number, String] as PropType<number | string | undefined>,
    clearable: Boolean,
    disabled: Boolean,
    readonly: Boolean,
    placeholder: String,
    maxlength: Number,
    showWordLimit: Boolean,
    size: String,
    autosize: [Boolean, Object] as PropType<boolean | Record<string, unknown> | undefined>,
  },
  emits: ['update:modelValue', 'input', 'change', 'enter', 'focus', 'blur', 'clear'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const handlers = {
        'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
        onInput: (v: unknown) => emit('input', v),
        onChange: (v: unknown) => emit('change', v),
        onPressEnter: (e: Event) => emit('enter', e),
        onFocus: (e: Event) => emit('focus', e),
        onBlur: (e: Event) => emit('blur', e),
        onClear: (e: Event) => emit('clear', e),
      }
      if (props.textarea || props.type === 'textarea') {
        const { rows: attrRows, readOnly: attrReadOnly, ...rest } = attrs as Record<string, any>
        const textareaAttrs: Record<string, unknown> = {}
        const rows = props.rows ?? attrRows
        if (rows !== undefined) textareaAttrs.rows = rows
        if (props.readonly || attrReadOnly) textareaAttrs.readOnly = true
        return h(
          ATextarea as any,
          {
            ...rest,
            modelValue: props.modelValue === undefined ? undefined : String(props.modelValue),
            placeholder: props.placeholder,
            disabled: props.disabled,
            allowClear: props.clearable,
            maxLength: props.maxlength,
            showWordLimit: props.showWordLimit,
            autoSize: props.autosize,
            textareaAttrs: Object.keys(textareaAttrs).length ? textareaAttrs : undefined,
            ...handlers,
          },
          slots,
        )
      }
      return h(
        AInput as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          type: props.type === 'password' ? 'password' : 'text',
          size: mapSize(props.size),
          allowClear: props.clearable,
          readonly: props.readonly,
          placeholder: props.placeholder,
          disabled: props.disabled,
          maxLength: props.maxlength,
          showWordLimit: props.showWordLimit,
          ...handlers,
        },
        slots,
      )
    }
  },
})

const InputNumber = defineComponent({
  name: 'UiArcoInputNumber',
  inheritAttrs: false,
  props: {
    modelValue: Number,
    min: Number,
    max: Number,
    step: Number,
    precision: Number,
    disabled: Boolean,
    readonly: Boolean,
    size: String,
    placeholder: String,
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(
        AInputNumber as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          min: props.min,
          max: props.max,
          step: props.step,
          precision: props.precision,
          disabled: props.disabled,
          readOnly: props.readonly,
          size: mapSize(props.size),
          placeholder: props.placeholder,
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
        },
        slots,
      )
  },
})

const Select = defineComponent({
  name: 'UiArcoSelect',
  inheritAttrs: false,
  props: {
    modelValue: [String, Number, Boolean, Array, Object] as PropType<unknown>,
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    placeholder: String,
    clearable: Boolean,
    disabled: Boolean,
    multiple: Boolean,
    filterable: Boolean,
    size: String,
  },
  emits: ['update:modelValue', 'change', 'clear', 'remove'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(
        ASelect as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          options: props.options,
          placeholder: props.placeholder,
          allowClear: props.clearable,
          allowSearch: props.filterable,
          disabled: props.disabled,
          multiple: props.multiple,
          size: mapSize(props.size),
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
          onClear: (e: Event) => emit('clear', e),
          onRemove: (v: unknown) => emit('remove', v),
        },
        slots,
      )
  },
})

const Switch = defineComponent({
  name: 'UiArcoSwitch',
  inheritAttrs: false,
  props: {
    modelValue: Boolean,
    disabled: Boolean,
    loading: Boolean,
    activeText: String,
    inactiveText: String,
    size: String,
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(
        ASwitch as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          disabled: props.disabled,
          loading: props.loading,
          size: props.size === 'large' ? 'medium' : mapSize(props.size),
          checkedText: props.activeText,
          uncheckedText: props.inactiveText,
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
        },
        slots,
      )
  },
})

const Checkbox = defineComponent({
  name: 'UiArcoCheckbox',
  inheritAttrs: false,
  props: {
    modelValue: [Boolean, Array] as PropType<boolean | Array<string | number> | undefined>,
    label: String,
    disabled: Boolean,
    indeterminate: Boolean,
    value: [String, Number, Boolean] as PropType<string | number | boolean | undefined>,
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const children = slots.default ? slots : { default: () => props.label }
      return h(
        ACheckbox as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          disabled: props.disabled,
          indeterminate: props.indeterminate,
          value: props.value,
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
        },
        children,
      )
    }
  },
})

const RadioGroup = defineComponent({
  name: 'UiArcoRadioGroup',
  inheritAttrs: false,
  props: {
    modelValue: [String, Number, Boolean] as PropType<string | number | boolean | undefined>,
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    disabled: Boolean,
    size: String,
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(
        ARadioGroup as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          options: props.options,
          disabled: props.disabled,
          size: mapSize(props.size),
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
        },
        slots,
      )
  },
})

/** Arco 没有 Segmented：用按钮形态的 RadioGroup 替代（见文件顶部说明） */
const Segmented = defineComponent({
  name: 'UiArcoSegmented',
  inheritAttrs: false,
  props: {
    modelValue: [String, Number, Boolean] as PropType<string | number | boolean | undefined>,
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    disabled: Boolean,
    size: String,
    block: Boolean,
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(
        ARadioGroup as any,
        {
          type: 'button',
          ...attrs,
          modelValue: props.modelValue,
          options: props.options,
          disabled: props.disabled,
          size: mapSize(props.size),
          style: mergeStyle((attrs as any).style, props.block ? { width: '100%' } : undefined),
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
        },
        slots,
      )
  },
})

const Slider = defineComponent({
  name: 'UiArcoSlider',
  inheritAttrs: false,
  props: {
    modelValue: [Number, Array] as PropType<number | number[] | undefined>,
    min: Number,
    max: Number,
    step: Number,
    disabled: Boolean,
    showTooltip: { type: Boolean, default: undefined },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(
        ASlider as any,
        {
          ...attrs,
          modelValue: props.modelValue,
          min: props.min,
          max: props.max,
          step: props.step,
          disabled: props.disabled,
          showTooltip: props.showTooltip,
          range: Array.isArray(props.modelValue),
          'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
          onChange: (v: unknown) => emit('change', v),
        },
        slots,
      )
  },
})

const Form = defineComponent({
  name: 'UiArcoForm',
  inheritAttrs: false,
  props: {
    model: Object as PropType<Record<string, unknown>>,
    rules: Object as PropType<Record<string, unknown>>,
    labelWidth: [Number, String] as PropType<number | string | undefined>,
    labelPosition: String,
    inline: Boolean,
    disabled: Boolean,
  },
  setup(props, { attrs, slots, expose }) {
    const formRef = ref<any>(null)

    expose({
      validate: async (): Promise<boolean> => {
        const inst = formRef.value
        if (!inst?.validate) return true
        try {
          const errors = await inst.validate()
          return !errors
        } catch {
          return false
        }
      },
      validateField: (field?: string) => formRef.value?.validateField?.(field),
      resetFields: (field?: string) => formRef.value?.resetFields?.(field),
      clearValidate: (field?: string) => formRef.value?.clearValidate?.(field),
      scrollToField: (field?: string) => formRef.value?.scrollToField?.(field),
    })

    return () =>
      h(
        AForm as any,
        {
          ...attrs,
          ref: formRef,
          model: props.model,
          rules: props.rules,
          layout: props.inline ? 'inline' : props.labelPosition === 'top' ? 'vertical' : 'horizontal',
          labelAlign: props.labelPosition === 'left' ? 'left' : props.labelPosition === 'top' ? 'left' : 'right',
          disabled: props.disabled,
          ...(props.labelWidth ? { labelColStyle: { width: px(props.labelWidth) } } : {}),
        },
        { default: () => slots.default?.() },
      )
  },
})

const FormItem = defineComponent({
  name: 'UiArcoFormItem',
  inheritAttrs: false,
  props: {
    label: String,
    prop: String,
    required: Boolean,
    rules: [Array, Object] as PropType<unknown[] | Record<string, unknown>>,
    error: String,
    labelWidth: [Number, String] as PropType<number | string | undefined>,
  },
  setup(props, { attrs, slots }) {
    return () => {
      const hasError = props.error !== undefined && props.error !== ''
      return h(
        AFormItem as any,
        {
          ...attrs,
          field: props.prop,
          label: props.label,
          required: props.required,
          rules: props.rules,
          help: hasError ? props.error : undefined,
          validateStatus: hasError ? 'error' : undefined,
          ...(props.labelWidth ? { labelColStyle: { width: px(props.labelWidth) } } : {}),
        },
        slots,
      )
    }
  },
})

const Table = defineComponent({
  name: 'UiArcoTable',
  inheritAttrs: false,
  props: {
    data: { type: Array as PropType<Record<string, any>[]>, default: () => [] },
    columns: { type: Array as PropType<UiColumn[]>, default: () => [] },
    rowKey: [String, Function] as PropType<string | ((row: any) => string) | undefined>,
    loading: Boolean,
    emptyText: String,
    size: String,
    maxHeight: [Number, String] as PropType<number | string | undefined>,
    height: [Number, String] as PropType<number | string | undefined>,
    stripe: Boolean,
    border: Boolean,
  },
  emits: ['row-click'],
  setup(props, { attrs, slots, emit }) {
    const sourceOf = (record: any): any => record?.__uiSource ?? record
    // arco 的 rowKey 只接受字符串；函数式 rowKey 用临时字段，并保留原对象引用
    const keyedData = computed<Record<string, any>[]>(() => {
      if (typeof props.rowKey !== 'function') return props.data
      const fn = props.rowKey
      return props.data.map((row) => ({ ...row, __uiRowKey: fn(row), __uiSource: row }))
    })
    const rowKeyValue = computed(() => {
      if (typeof props.rowKey === 'function') return '__uiRowKey'
      if (props.rowKey) return props.rowKey
      const first = props.data[0]
      return first && 'id' in first ? 'id' : 'key'
    })

    return () => {
      const tableSlots: Record<string, (...args: any[]) => any> = {}
      const columns = props.columns.map((col) => {
        const cell = slots['cell-' + col.key]
        if (cell) {
          tableSlots['cell-' + col.key] = (data?: any) => {
            const row = sourceOf(data?.record)
            return cell({ row, index: data?.rowIndex, value: row?.[col.key], column: data?.column })
          }
        }
        return {
          dataIndex: col.key,
          title: col.title,
          width: numeric(col.width),
          minWidth: numeric(col.minWidth),
          align: col.align,
          ellipsis: col.ellipsis,
          slotName: cell ? 'cell-' + col.key : undefined,
        }
      })
      if (slots.empty) tableSlots.empty = () => slots.empty!()
      else if (props.emptyText) tableSlots.empty = () => props.emptyText
      if (slots.append) tableSlots.footer = () => slots.append!()

      const { height, maxHeight, ...rest } = attrs as Record<string, any>
      const scroll =
        props.height ?? height
          ? { y: numeric(props.height ?? height) ?? props.height ?? height }
          : props.maxHeight ?? maxHeight
            ? { maxHeight: numeric(props.maxHeight ?? maxHeight) ?? props.maxHeight ?? maxHeight }
            : undefined

      return h(
        ATable as any,
        {
          ...rest,
          rowKey: rowKeyValue.value,
          data: keyedData.value,
          columns,
          loading: props.loading,
          size: mapSize(props.size),
          stripe: props.stripe,
          bordered: props.border,
          pagination: false,
          scroll,
          onRowClick: (record: any) => emit('row-click', sourceOf(record)),
        },
        tableSlots,
      )
    }
  },
})

const Descriptions = defineComponent({
  name: 'UiArcoDescriptions',
  inheritAttrs: false,
  props: {
    items: { type: Array as PropType<{ label: string; value?: unknown; span?: number }[]>, default: () => [] },
    column: Number,
    border: Boolean,
    size: String,
    direction: String,
    title: String,
  },
  setup(props, { attrs, slots }) {
    return () => {
      const data = props.items.map((item) => ({ label: item.label, value: item.value, span: item.span }))
      const children: Record<string, any> = {}
      if (!data.length && slots.default) children.default = () => slots.default!()
      return h(
        ADescriptions as any,
        {
          ...attrs,
          data: data.length ? data : undefined,
          column: props.column,
          bordered: props.border,
          size: mapSize(props.size),
          layout: props.direction === 'vertical' ? 'vertical' : 'horizontal',
          title: props.title,
        },
        children,
      )
    }
  },
})

const DescriptionItem = defineComponent({
  name: 'UiArcoDescriptionItem',
  inheritAttrs: false,
  props: { label: String, span: Number },
  setup(props, { attrs, slots }) {
    return () => h(ADescriptionsItem as any, { ...attrs, label: props.label, span: props.span }, slots)
  },
})

const Dialog = defineComponent({
  name: 'UiArcoDialog',
  inheritAttrs: false,
  props: {
    modelValue: Boolean,
    title: String,
    width: [Number, String] as PropType<number | string | undefined>,
    fullscreen: Boolean,
    closeOnClickModal: { type: Boolean, default: true },
    showClose: { type: Boolean, default: true },
    destroyOnClose: Boolean,
  },
  emits: ['update:modelValue', 'open', 'close', 'closed', 'ok', 'cancel'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const children: Record<string, any> = {}
      if (slots.title) children.title = () => slots.title!()
      if (slots.default) children.default = () => slots.default!()
      if (slots.footer) children.footer = () => slots.footer!()
      return h(
        AModal as any,
        {
          ...attrs,
          visible: props.modelValue,
          title: props.title,
          width: props.width === undefined ? undefined : px(props.width),
          fullscreen: props.fullscreen,
          maskClosable: props.closeOnClickModal,
          closable: props.showClose,
          unmountOnClose: props.destroyOnClose,
          footer: Boolean(slots.footer),
          'onUpdate:visible': (v: boolean) => emit('update:modelValue', v),
          onOpen: () => emit('open'),
          onClose: () => {
            emit('close')
            emit('closed')
          },
          onCancel: (e: Event) => {
            emit('update:modelValue', false)
            emit('cancel', e)
          },
          onOk: (e: Event) => emit('ok', e),
        },
        children,
      )
    }
  },
})

const Empty = defineComponent({
  name: 'UiArcoEmpty',
  inheritAttrs: false,
  props: { description: String, image: String },
  setup(props, { attrs, slots }) {
    return () => h(AEmpty as any, { ...attrs, description: props.description, imgSrc: props.image }, slots)
  },
})

const Skeleton = defineComponent({
  name: 'UiArcoSkeleton',
  inheritAttrs: false,
  props: {
    loading: { type: Boolean, default: true },
    rows: { type: Number, default: 3 },
    animated: Boolean,
  },
  setup(props, { attrs, slots }) {
    return () => {
      const children: Record<string, any> = {}
      // arco：default 是加载中骨架，content 是加载完成后的真实内容
      if (props.rows > 0) {
        children.default = () =>
          Array.from({ length: props.rows }, (_, i) => h(SkeletonLine as any, { key: i }))
      }
      if (slots.default) children.content = () => slots.default!()
      return h(ASkeleton as any, { ...attrs, loading: props.loading, animation: props.animated }, children)
    }
  },
})

const Progress = defineComponent({
  name: 'UiArcoProgress',
  inheritAttrs: false,
  props: {
    percentage: Number,
    percent: Number,
    type: String,
    status: String,
    strokeWidth: Number,
    showText: { type: Boolean, default: undefined },
    color: [String, Object] as PropType<string | Record<string, string> | undefined>,
  },
  setup(props, { attrs, slots }) {
    return () => {
      const showText = props.showText === undefined ? undefined : props.showText
      return h(
        AProgress as any,
        {
          ...attrs,
          percent: Math.max(0, Math.min(100, Number(props.percentage ?? props.percent) || 0)),
          type: props.type === 'circle' ? 'circle' : props.type === 'line' ? 'line' : undefined,
          status: props.status ? PROGRESS_STATUS[props.status] ?? props.status : undefined,
          strokeWidth: props.strokeWidth,
          color: props.color,
          showText,
        },
        slots,
      )
    }
  },
})

const Alert = defineComponent({
  name: 'UiArcoAlert',
  inheritAttrs: false,
  props: {
    type: String,
    title: String,
    description: String,
    showIcon: { type: Boolean, default: undefined },
    closable: Boolean,
    center: Boolean,
  },
  emits: ['close'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const children: Record<string, any> = {}
      if (slots.default) children.default = () => slots.default!()
      else if (props.description) children.default = () => props.description
      return h(
        AAlert as any,
        {
          ...attrs,
          type: props.type ? ALERT_TYPE[props.type] ?? props.type : undefined,
          title: props.title,
          showIcon: props.showIcon,
          closable: props.closable,
          center: props.center,
          onClose: (e: Event) => emit('close', e),
        },
        children,
      )
    }
  },
})

const Divider = defineComponent({
  name: 'UiArcoDivider',
  inheritAttrs: false,
  props: {
    vertical: Boolean,
    contentPosition: String,
    borderStyle: String,
    dashed: Boolean,
  },
  setup(props, { attrs, slots }) {
    return () => {
      const type = props.borderStyle ?? (props.dashed ? 'dashed' : undefined)
      return h(
        ADivider as any,
        {
          ...attrs,
          direction: props.vertical ? 'vertical' : 'horizontal',
          orientation: props.contentPosition,
          type,
        },
        props.vertical ? undefined : slots,
      )
    }
  },
})

const Tooltip = defineComponent({
  name: 'UiArcoTooltip',
  inheritAttrs: false,
  props: {
    content: String,
    placement: String,
    effect: String,
    disabled: Boolean,
  },
  setup(props, { attrs, slots }) {
    return () =>
      h(
        ATooltip as any,
        {
          ...attrs,
          content: props.content,
          position: mapPlacement(props.placement),
          disabled: props.disabled,
        },
        slots,
      )
  },
})

const Popover = defineComponent({
  name: 'UiArcoPopover',
  inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: undefined },
    title: String,
    content: String,
    width: { type: [Number, String] as PropType<number | string | undefined>, default: 240 },
    placement: { type: String, default: 'bottom' },
    trigger: { type: String, default: 'click' },
    disabled: Boolean,
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, slots, emit }) {
    // 自己维护一份可见性：父组件用 v-model 时以 props 为准，没绑定时也能点击打开；
    // 同时避免 disabled 期间 Arco 内部状态被点开、解除禁用后突然弹出。
    const inner = ref(false)
    return () => {
      const children: Record<string, any> = {}
      if (slots.default) children.default = () => slots.default!()
      if (slots.content) children.content = () => slots.content!()
      return h(
        APopover as any,
        {
          ...attrs,
          popupVisible: props.disabled ? false : props.modelValue ?? inner.value,
          title: props.title,
          content: props.content,
          position: mapPlacement(props.placement),
          trigger: props.disabled ? [] : props.trigger,
          contentStyle: props.width ? { width: px(props.width) } : undefined,
          'onUpdate:popupVisible': (v: boolean) => {
            if (props.disabled) return
            inner.value = v
            emit('update:modelValue', v)
          },
        },
        children,
      )
    }
  },
})

const Dropdown = defineComponent({
  name: 'UiArcoDropdown',
  inheritAttrs: false,
  props: {
    items: { type: Array as PropType<UiDropdownItem[]>, default: () => [] },
    trigger: { type: String, default: 'click' },
    placement: { type: String, default: 'bottom-end' },
  },
  emits: ['select'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const children: Record<string, any> = {}
      if (slots.default) children.default = () => slots.default!()
      if (slots.content) {
        children.content = () => slots.content!()
      } else {
        children.content = () => {
          return props.items.map((item, index) =>
            h(
              Doption as any,
              {
                key: item.key,
                value: item.key,
                disabled: item.disabled,
                // arco 的 Doption 没有 divided/danger，用上边框与内联色模拟
                style: {
                  borderTop: item.divided && index > 0 ? '1px solid var(--color-neutral-3)' : undefined,
                  marginTop: item.divided && index > 0 ? '4px' : undefined,
                },
              },
              {
                default: () =>
                  item.danger
                    ? h('span', { style: { color: 'rgb(var(--danger-6))' } }, item.label)
                    : item.label,
              },
            ),
          )
        }
      }
      return h(
        ADropdown as any,
        {
          ...attrs,
          trigger: props.trigger,
          position: mapPlacement(props.placement),
          onSelect: (v: unknown) => emit('select', v),
        },
        children,
      )
    }
  },
})

const Popconfirm = defineComponent({
  name: 'UiArcoPopconfirm',
  inheritAttrs: false,
  props: {
    title: String,
    content: String,
    confirmText: String,
    cancelText: String,
    confirmButtonText: String,
    cancelButtonText: String,
    placement: String,
    width: [Number, String] as PropType<number | string | undefined>,
    disabled: Boolean,
  },
  emits: ['confirm', 'cancel'],
  setup(props, { attrs, slots, emit }) {
    // arco 的 Popconfirm 没有 disabled：自己维护可见性，禁用时保持关闭
    const inner = ref(false)
    return () => {
      const children: Record<string, any> = {}
      if (slots.default) children.default = () => slots.default!()
      if (slots.content) children.content = () => slots.content!()
      return h(
        APopconfirm as any,
        {
          ...attrs,
          content: props.title ?? props.content ?? '确认执行该操作？',
          okText: props.confirmText ?? props.confirmButtonText ?? '确定',
          cancelText: props.cancelText ?? props.cancelButtonText ?? '取消',
          position: mapPlacement(props.placement),
          contentStyle: props.width ? { width: px(props.width) } : undefined,
          popupVisible: props.disabled ? false : inner.value,
          'onUpdate:popupVisible': (v: boolean) => {
            if (props.disabled) return
            inner.value = v
          },
          onOk: () => emit('confirm'),
          onCancel: () => emit('cancel'),
        },
        children,
      )
    }
  },
})

const Avatar = defineComponent({
  name: 'UiArcoAvatar',
  inheritAttrs: false,
  props: {
    src: String,
    size: [Number, String] as PropType<number | string | undefined>,
    shape: String,
  },
  setup(props, { attrs, slots }) {
    return () => {
      let size: number | undefined
      if (typeof props.size === 'number') size = props.size
      else if (props.size === 'small') size = 24
      else if (props.size === 'large') size = 40
      else if (props.size === 'default') size = 32
      else if (props.size !== undefined) size = numeric(props.size)
      return h(
        AAvatar as any,
        { ...attrs, imageUrl: props.src || undefined, size, shape: props.shape },
        slots,
      )
    }
  },
})

/** 只是图标容器（Element Plus 图标是 1em/currentColor 的 SVG） */
const Icon = defineComponent({
  name: 'UiArcoIcon',
  inheritAttrs: false,
  props: {
    size: [Number, String] as PropType<number | string | undefined>,
    color: String,
  },
  setup(props, { attrs, slots }) {
    const fontSize = () => {
      if (props.size === undefined) return undefined
      if (typeof props.size === 'number') return px(props.size)
      if (props.size === 'small') return '14px'
      if (props.size === 'large') return '18px'
      if (props.size === 'default') return '16px'
      return props.size
    }
    return () =>
      h(
        'span',
        {
          ...attrs,
          class: ['ui-icon', (attrs as any).class],
          style: mergeStyle((attrs as any).style, {
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            lineHeight: 1,
            fontSize: fontSize(),
            color: props.color,
          }),
        },
        slots.default?.(),
      )
  },
})

const components: UiAdapter['components'] = {
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
  DescriptionItem,
  Dialog,
  Empty,
  Skeleton,
  Progress,
  Alert,
  Divider,
  Tooltip,
  Popover,
  Dropdown,
  Popconfirm,
  Avatar,
  Icon,
}

const adapter: UiAdapter = {
  id: 'arco',
  name: 'Arco Design',
  Root: Root as Component,
  components,
  applyTheme,
}

export default adapter