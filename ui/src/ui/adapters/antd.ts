/**
 * Ant Design Vue 4 适配器。
 *
 * 只做「契约 → antd」的薄胶水：属性名 / 事件名 / 插槽名一律按 src/ui/UI_CONTRACT.md，
 * 不把 antd 原生 API 漏给视图。antd v4 是 CSS-in-JS，这里额外引入官方 reset.css。
 *
 * 已对齐：
 * - a-config-provider + theme.darkAlgorithm / token.colorPrimary 做 applyTheme，Root 包住整站。
 * - a-table + columns.customRender 转调 #cell-<key>，分页默认关闭。
 * - a-form / a-form-item：prop → name，labelWidth → labelCol.style.width，error → help。
 * - a-modal：open + footer=null（无 #footer 时不渲染默认按钮）。
 * - a-dropdown：menu.items，placement 命名翻译。
 *
 * 已知降级：
 * - 没有 a-icon 组件：UiIcon 用 span 包一层透传 svg。
 * - a-tag 无 size 属性：size 忽略，round 用 inline border-radius 兜。
 * - a-popover 无 width：width 写进 overlayStyle。
 */
import 'ant-design-vue/dist/reset.css'
import { computed, defineComponent, h, ref } from 'vue'
import type { Component, PropType } from 'vue'
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
  Dropdown as ADropdown,
  Empty as AEmpty,
  Form as AForm,
  FormItem as AFormItem,
  Input as AInput,
  InputNumber as AInputNumber,
  InputPassword as AInputPassword,
  Modal as AModal,
  Popconfirm as APopconfirm,
  Popover as APopover,
  Progress as AProgress,
  Radio as ARadio,
  RadioGroup as ARadioGroup,
  Segmented as ASegmented,
  Select as ASelect,
  Skeleton as ASkeleton,
  Slider as ASlider,
  Switch as ASwitch,
  Table as ATable,
  Tag as ATag,
  Textarea as ATextarea,
  Tooltip as ATooltip,
  theme as antdTheme,
} from 'ant-design-vue'
import zhCN from 'ant-design-vue/es/locale/zh_CN'
import { uiTheme } from '../theme-bridge'
import type { UiAdapter, UiColumn, UiDropdownItem, UiOption, UiThemeTokens } from '../types'

/** 当前主题令牌：Root 读这里，applyTheme 直接写这里（provider 加载后立即调用） */
const themeTokens = ref<UiThemeTokens>({ accent: uiTheme.accent, dark: uiTheme.dark })

/** 补齐 antd 没有对应 prop 的契约能力（斑马纹、卡片标题排版等） */
const SHIM_CSS = [
  '.ui-antd-row-stripe > td.ant-table-cell { background: var(--bg-card-2, rgba(0, 0, 0, 0.02)); }',
  '.ui-card-head { display: flex; flex-direction: column; gap: 2px; min-width: 0; }',
  '.ui-card-title { font-size: 13px; font-weight: 600; line-height: 1.5; }',
  '.ui-card-subtitle { font-size: 12px; opacity: 0.7; line-height: 1.4; }',
  '.ui-card-extra { display: inline-flex; align-items: center; gap: 8px; }',
  '.ui-antd-table-wrap { overflow: auto; }',
  '.ui-ant-icon { display: inline-flex; align-items: center; justify-content: center; line-height: 1; vertical-align: middle; }',
].join('\n')

/** 把 400 / '400px' 归一成数字；clamp()/calc() 等返回 undefined */
function parsePx(v: number | string | undefined): number | undefined {
  if (typeof v === 'number') return Number.isFinite(v) ? v : undefined
  if (typeof v !== 'string') return undefined
  const t = v.trim()
  if (!/^[0-9]+(\.[0-9]+)?px$/.test(t)) return undefined
  const n = Number.parseFloat(t)
  return Number.isFinite(n) ? n : undefined
}

/** 契约 placement → antd placement（bottom-end → bottomRight） */
function antPlacement(p: string): string {
  switch (p) {
    case 'top-start': return 'topLeft'
    case 'top-end': return 'topRight'
    case 'bottom-start': return 'bottomLeft'
    case 'bottom-end': return 'bottomRight'
    case 'left-start':
    case 'left-end': return 'left'
    case 'right-start':
    case 'right-end': return 'right'
    default: return p || 'bottom'
  }
}

/** 卡片：#header / #title 放标题区，#extra 在标题右侧，subtitle 在标题下方 */
const Card = defineComponent({
  name: 'UiAntCard',
  inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    padding: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    const bodyStyle = (): Record<string, string> | undefined => {
      if (props.padding === 'none') return { padding: '0' }
      if (props.padding === 'small') return { padding: '12px' }
      return undefined
    }
    return () => {
      const rawHead = slots.header ?? slots.title
      const fallback = props.title || props.subtitle
        ? () => h('div', { class: 'ui-card-head ui-antd-card-head' }, [
            props.title ? h('div', { class: 'ui-card-title ui-antd-card-title' }, props.title) : null,
            props.subtitle ? h('div', { class: 'ui-card-subtitle ui-antd-card-subtitle' }, props.subtitle) : null,
          ])
        : undefined
      const head = rawHead
        ? () => h('div', { class: 'ui-card-head ui-antd-card-head' }, [
            h('div', { class: 'ui-card-title ui-antd-card-title' }, rawHead()),
            props.subtitle ? h('div', { class: 'ui-card-subtitle ui-antd-card-subtitle' }, props.subtitle) : null,
          ])
        : fallback
      const extra = slots.extra
        ? () => h('div', { class: 'ui-card-extra' }, slots.extra!())
        : undefined
      return h(ACard as Component, {
        ...attrs,
        bodyStyle: bodyStyle() ?? (attrs.bodyStyle as Record<string, string> | undefined),
      }, {
        default: slots.default,
        ...(head ? { title: head } : {}),
        ...(extra ? { extra } : {}),
      })
    }
  },
})

/** 按钮：plain 主色按钮 = 描边样式（antd 无 plain 属性） */
const Button = defineComponent({
  name: 'UiAntButton',
  inheritAttrs: false,
  props: {
    type: { type: String, default: 'default' },
    size: { type: String, default: 'default' },
    plain: { type: Boolean, default: false },
    round: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots }) {
    return () => {
      const text = props.type === 'text'
      const primary = props.type === 'primary' || props.type === 'ok' || props.type === 'success'
      const danger = props.type === 'danger' || props.type === 'error'
      const plain = props.plain && (primary || danger)
      const userStyle = attrs.style as string | Record<string, string> | undefined
      const plainAccent = danger ? 'var(--danger)' : 'var(--accent)'
      const plainStyle = {
        color: plainAccent,
        borderColor: plainAccent,
        background: 'transparent',
        boxShadow: 'none',
      }
      const style = plain
        ? (typeof userStyle === 'string' ? userStyle : { ...plainStyle, ...userStyle })
        : userStyle
      return h(AButton as Component, {
        ...attrs,
        type: text ? 'text' : (primary || danger) && !plain ? 'primary' : 'default',
        danger: plain ? false : danger,
        size: props.size === 'small' ? 'small' : props.size === 'large' ? 'large' : 'middle',
        shape: props.round ? 'round' : undefined,
        loading: props.loading,
        disabled: props.disabled,
        style,
      }, slots)
    }
  },
})

type TagSemantic = 'success' | 'warning' | 'error' | 'info' | 'accent' | 'plain'

const TAG_ALIAS: Record<string, TagSemantic> = {
  ok: 'success', success: 'success',
  warn: 'warning', warning: 'warning',
  danger: 'error', error: 'error',
  info: 'info',
  accent: 'accent', primary: 'accent',
  plain: 'plain', default: 'plain',
}

const TAG_COLOR: Record<TagSemantic, string | undefined> = {
  success: 'success',
  warning: 'warning',
  error: 'error',
  info: 'processing',
  accent: undefined,
  plain: undefined,
}

const TAG_CSS_VAR: Record<TagSemantic, string> = {
  success: 'var(--ok)',
  warning: 'var(--warn)',
  error: 'var(--danger)',
  info: 'var(--info)',
  accent: 'var(--accent)',
  plain: 'var(--border-strong)',
}

/** 标签：语义色 → antd 预设色；plain/dark 用内联样式微调，round 用圆角兜 */
const Tag = defineComponent({
  name: 'UiAntTag',
  inheritAttrs: false,
  props: {
    type: { type: String, default: 'plain' },
    effect: { type: String, default: 'light' },
    size: { type: String, default: undefined },
    round: { type: Boolean, default: false },
    closable: { type: Boolean, default: false },
  },
  emits: ['close'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const sem = TAG_ALIAS[props.type] ?? 'plain'
      const cssVar = TAG_CSS_VAR[sem]
      const style: Record<string, string> = {}
      if (props.effect === 'plain') {
        style.background = 'transparent'
        style.borderColor = cssVar
        style.color = cssVar
      } else if (props.effect === 'dark') {
        style.background = cssVar
        style.borderColor = cssVar
        style.color = '#fff'
      }
      if (props.round) style.borderRadius = '999px'
      const userStyle = attrs.style as Record<string, string> | undefined
      return h(ATag as Component, {
        ...attrs,
        color: props.effect === 'plain'
          ? undefined
          : sem === 'accent' ? themeTokens.value.accent : TAG_COLOR[sem],
        closable: props.closable,
        onClose: (e: MouseEvent) => {
          const own = attrs.onClose
          if (typeof own === 'function') (own as (ev: MouseEvent) => void)(e)
          emit('close')
        },
        style: { ...userStyle, ...style },
      }, { default: slots.default })
    }
  },
})

/** 输入框：type=textarea / password 走独立导出；clearable → allowClear */
const Input = defineComponent({
  name: 'UiAntInput',
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    type: { type: String, default: 'text' },
    rows: { type: Number, default: undefined },
    clearable: { type: Boolean, default: false },
    showWordLimit: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'enter', 'clear'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const userEnter = attrs.onPressEnter
      const common: Record<string, unknown> = {
        ...attrs,
        value: props.modelValue,
        allowClear: (attrs.allowClear as boolean | undefined) ?? props.clearable,
        showCount: props.showWordLimit || (attrs.showCount as boolean | undefined),
        'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
        onClear: () => emit('clear'),
        onPressEnter: (e: KeyboardEvent) => {
          if (typeof userEnter === 'function') (userEnter as (ev: KeyboardEvent) => void)(e)
          emit('enter')
        },
      }
      if (props.type === 'textarea') {
        return h(ATextarea as Component, { ...common, rows: props.rows }, slots)
      }
      if (props.type === 'password') {
        return h(AInputPassword as Component, common, slots)
      }
      return h(AInput as Component, common, slots)
    }
  },
})

/** 数字输入：v-model 走 update:value */
const InputNumber = defineComponent({
  name: 'UiAntInputNumber',
  inheritAttrs: false,
  props: {
    modelValue: { type: [Number, String] as PropType<unknown>, default: undefined },
    min: { type: Number, default: undefined },
    max: { type: Number, default: undefined },
    step: { type: Number, default: undefined },
    precision: { type: Number, default: undefined },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(AInputNumber as Component, {
      ...attrs,
      value: props.modelValue,
      min: props.min,
      max: props.max,
      step: props.step,
      precision: props.precision,
      'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    })
  },
})

/** 下拉：options 直传；multiple 显式传入或数组值自动多选 */
const Select = defineComponent({
  name: 'UiAntSelect',
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number, Array] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    placeholder: { type: String, default: '' },
    clearable: { type: Boolean, default: false },
    filterable: { type: Boolean, default: false },
    multiple: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () => h(ASelect as Component, {
      ...attrs,
      value: props.modelValue,
      options: props.options,
      placeholder: props.placeholder,
      allowClear: (attrs.allowClear as boolean | undefined) ?? props.clearable,
      showSearch: (attrs.showSearch as boolean | undefined) ?? props.filterable,
      mode: (attrs.mode as string | undefined)
        ?? (props.multiple || Array.isArray(props.modelValue) ? 'multiple' : undefined),
      'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    }, slots)
  },
})

/** 开关 */
const Switch = defineComponent({
  name: 'UiAntSwitch',
  inheritAttrs: false,
  props: {
    modelValue: { type: [Boolean, String, Number] as PropType<unknown>, default: false },
    size: { type: String, default: undefined },
    activeText: { type: String, default: '' },
    inactiveText: { type: String, default: '' },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(ASwitch as Component, {
      ...attrs,
      checked: props.modelValue,
      checkedChildren: props.activeText || undefined,
      unCheckedChildren: props.inactiveText || undefined,
      size: props.size === 'small' ? 'small' : 'default',
      'onUpdate:checked': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    })
  },
})

/** 复选框：label 作为默认插槽内容 */
const Checkbox = defineComponent({
  name: 'UiAntCheckbox',
  inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () => h(ACheckbox as Component, {
      ...attrs,
      checked: props.modelValue,
      'onUpdate:checked': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    }, { default: slots.default ?? (() => props.label) })
  },
})

/** 单选组：options → a-radio 子节点 */
const RadioGroup = defineComponent({
  name: 'UiAntRadioGroup',
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, slots, emit }) {
    return () => h(ARadioGroup as Component, {
      ...attrs,
      value: props.modelValue,
      'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    }, {
      default: () => slots.default
        ? slots.default()
        : props.options.map((o) =>
            h(ARadio, { key: o.value, value: o.value, disabled: o.disabled }, () => o.label)),
    })
  },
})

/** 分段控件 */
const Segmented = defineComponent({
  name: 'UiAntSegmented',
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    block: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(ASegmented as Component, {
      ...attrs,
      value: props.modelValue,
      options: props.options,
      block: props.block,
      'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    })
  },
})

/** 滑块 */
const Slider = defineComponent({
  name: 'UiAntSlider',
  inheritAttrs: false,
  props: {
    modelValue: { type: [Number, Array] as PropType<unknown>, default: undefined },
    showTooltip: { type: Boolean, default: true },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(ASlider as Component, {
      ...attrs,
      value: props.modelValue,
      range: (attrs.range as boolean | undefined) ?? Array.isArray(props.modelValue),
      tooltipOpen: props.showTooltip ? undefined : false,
      'onUpdate:value': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    })
  },
})

interface AntFormApi {
  validate: () => Promise<unknown>
  resetFields: () => void
  clearValidate: () => void
}

/** 表单：暴露 validate():Promise<boolean> / resetFields / clearValidate */
const Form = defineComponent({
  name: 'UiAntForm',
  inheritAttrs: false,
  props: {
    model: { type: Object as PropType<Record<string, unknown>>, default: undefined },
    rules: { type: Object as PropType<Record<string, unknown>>, default: undefined },
    labelWidth: { type: [String, Number], default: undefined },
    labelPosition: { type: String, default: undefined },
    inline: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots, expose }) {
    const inner = ref<AntFormApi | null>(null)
    const width = computed(() => {
      const w = props.labelWidth
      if (w === undefined || w === '') return undefined
      return typeof w === 'number' ? w + 'px' : String(w)
    })
    expose({
      validate: async (): Promise<boolean> => {
        try {
          await inner.value?.validate()
          return true
        } catch {
          return false
        }
      },
      resetFields: (): void => inner.value?.resetFields(),
      clearValidate: (): void => inner.value?.clearValidate(),
    })
    return () => h(AForm as Component, {
      ...attrs,
      ref: inner,
      model: props.model,
      rules: props.rules,
      layout: props.inline ? 'inline' : props.labelPosition === 'top' ? 'vertical' : 'horizontal',
      labelAlign: props.labelPosition === 'left' ? 'left' : 'right',
      labelCol: width.value ? { style: { width: width.value } } : undefined,
      wrapperCol: width.value ? { style: { flex: 1 } } : undefined,
    }, slots)
  },
})

/** 表单项：prop → name，labelWidth → labelCol，error → help + validateStatus */
const FormItem = defineComponent({
  name: 'UiAntFormItem',
  inheritAttrs: false,
  props: {
    label: { type: String, default: '' },
    prop: { type: String, default: undefined },
    name: { type: String, default: undefined },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
    labelWidth: { type: [String, Number], default: undefined },
    rules: { type: [Object, Array] as PropType<unknown>, default: undefined },
  },
  setup(props, { attrs, slots }) {
    return () => {
      const width = props.labelWidth === undefined || props.labelWidth === ''
        ? undefined
        : typeof props.labelWidth === 'number' ? props.labelWidth + 'px' : String(props.labelWidth)
      const attrLabelCol = attrs.labelCol as Record<string, unknown> | undefined
      const labelCol = width
        ? { ...(attrLabelCol ?? {}), style: { ...((attrLabelCol?.style as Record<string, string>) ?? {}), width } }
        : attrLabelCol
      return h(AFormItem as Component, {
        ...attrs,
        name: props.name ?? props.prop ?? (attrs.name as string | undefined),
        label: props.label || undefined,
        required: props.required || undefined,
        rules: props.rules as never,
        validateStatus: props.error ? 'error' : (attrs.validateStatus as string | undefined),
        help: props.error || (attrs.help as unknown),
        labelCol,
        extra: slots.extra,
      }, {
        default: slots.default,
        ...(slots.label ? { label: slots.label } : {}),
        ...(slots.error ? { help: slots.error } : {}),
      })
    }
  },
})

/** 表格：columns → antd columns，#cell-<key> → customRender，#empty → locale.emptyText */
const Table = defineComponent({
  name: 'UiAntTable',
  inheritAttrs: false,
  props: {
    data: { type: Array as PropType<Record<string, unknown>[]>, default: () => [] },
    columns: { type: Array as PropType<UiColumn[]>, default: () => [] },
    rowKey: {
      type: [String, Function] as PropType<string | ((record: Record<string, unknown>, index: number) => string)>,
      default: undefined,
    },
    emptyText: { type: String, default: undefined },
    size: { type: String, default: 'default' },
    stripe: { type: Boolean, default: false },
    border: { type: Boolean, default: false },
    height: { type: [String, Number], default: undefined },
    maxHeight: { type: [String, Number], default: undefined },
  },
  emits: ['row-click'],
  setup(props, { attrs, slots, emit }) {
    const defaultRowKey = (record: Record<string, unknown>, index: number): string =>
      String(record.key ?? record.id ?? index)
    return () => {
      const cols = props.columns.map((col) => {
        const cell = slots['cell-' + col.key]
        return {
          key: col.key,
          dataIndex: col.key,
          title: col.title,
          width: typeof col.width === 'string' ? parsePx(col.width) : col.width,
          minWidth: typeof col.minWidth === 'string' ? parsePx(col.minWidth) : col.minWidth,
          align: col.align,
          ellipsis: col.ellipsis ? { showTitle: true } : undefined,
          customRender: cell
            ? (arg: { text: unknown; record: Record<string, unknown>; index: number }) =>
                cell({ row: arg.record, index: arg.index, value: arg.text ?? arg.record?.[col.key] })
            : undefined,
        }
      })
      const limit = parsePx(props.height ?? props.maxHeight)
      const rawLimit = props.height ?? props.maxHeight
      const empty = slots.empty
        ? h('div', { class: 'ui-antd-empty' }, slots.empty())
        : (props.emptyText ?? '暂无数据')
      const rowClass = props.stripe
        ? (_r: unknown, index: number): string => (index % 2 === 1 ? 'ui-antd-row-stripe' : '')
        : undefined
      const table = h(ATable as Component, {
        ...attrs,
        columns: cols,
        dataSource: props.data,
        rowKey: props.rowKey ?? defaultRowKey,
        size: props.size === 'small' ? 'small' : props.size === 'large' ? 'large' : 'middle',
        bordered: (attrs.bordered as boolean | undefined) ?? props.border,
        pagination: (attrs.pagination as unknown) ?? false,
        scroll: limit !== undefined ? { y: limit } : (attrs.scroll as unknown),
        locale: { emptyText: empty },
        rowClassName: (attrs.rowClassName as unknown) ?? rowClass,
        customRow: (record: Record<string, unknown>, index: number) => ({
          onClick: () => emit('row-click', record, index),
        }),
      })
      const wrapped = limit !== undefined || rawLimit === undefined
        ? table
        : h('div', {
            class: 'ui-antd-table-wrap',
            style: { maxHeight: String(rawLimit), overflow: 'auto' },
          }, [table])
      return slots.append
        ? h('div', { class: 'ui-table-wrap' }, [wrapped, h('div', { class: 'ui-table-append' }, slots.append())])
        : wrapped
    }
  },
})

interface UiDescriptionRow {
  label: string
  value?: unknown
  span?: number
}

/** 描述列表：items → a-descriptions-item；direction → layout */
const Descriptions = defineComponent({
  name: 'UiAntDescriptions',
  inheritAttrs: false,
  props: {
    items: { type: Array as PropType<UiDescriptionRow[]>, default: () => [] },
    column: { type: [Number, Object] as PropType<number | Record<string, number>>, default: undefined },
    border: { type: Boolean, default: false },
    direction: { type: String, default: 'horizontal' },
    size: { type: String, default: undefined },
    title: { type: String, default: '' },
  },
  setup(props, { attrs, slots }) {
    return () => h(ADescriptions as Component, {
      ...attrs,
      title: props.title || undefined,
      column: props.column,
      size: props.size === 'default' ? undefined : props.size,
      layout: props.direction === 'vertical' ? 'vertical' : 'horizontal',
      bordered: props.border,
    }, {
      default: slots.default
        ? slots.default
        : () => props.items.map((item, i) => h(ADescriptionsItem as Component, {
            key: item.label + '-' + i,
            label: item.label,
            span: item.span,
          }, () => String(item.value ?? '-'))),
    })
  },
})

/** 对话框：open/onUpdate:open；无 #footer 时 footer=null；@closed 用 afterClose 映射 */
const Dialog = defineComponent({
  name: 'UiAntDialog',
  inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    width: { type: [String, Number], default: undefined },
    closeOnClickModal: { type: Boolean, default: undefined },
  },
  emits: ['update:modelValue', 'closed'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const userCancel = attrs.onCancel
      return h(AModal as Component, {
        ...attrs,
        open: props.modelValue,
        title: props.title,
        width: props.width,
        maskClosable: (props.closeOnClickModal
          ?? (attrs.maskClosable as boolean | undefined)
          ?? true),
        footer: slots.footer ? slots.footer() : null,
        'onUpdate:open': (v: boolean) => emit('update:modelValue', v),
        onCancel: () => {
          if (typeof userCancel === 'function') (userCancel as () => void)()
          emit('update:modelValue', false)
        },
        afterClose: () => emit('closed'),
      }, { default: slots.default })
    }
  },
})

/** 气泡卡片：#default 触发器 / #content 内容；width 注入 overlayStyle */
const Popover = defineComponent({
  name: 'UiAntPopover',
  inheritAttrs: false,
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
    return () => {
      const w = typeof props.width === 'number' ? props.width + 'px' : props.width
      const userStyle = attrs.overlayStyle as Record<string, string> | undefined
      return h(APopover as Component, {
        ...attrs,
        open: props.disabled ? false : props.modelValue,
        title: props.title || undefined,
        placement: antPlacement(props.placement),
        trigger: props.trigger === 'manual' ? [] : props.trigger,
        overlayStyle: { ...userStyle, width: w },
        'onUpdate:open': (v: boolean) => {
          if (!props.disabled) emit('update:modelValue', v)
        },
      }, {
        default: slots.default,
        ...(slots.content ? { content: slots.content } : {}),
      })
    }
  },
})

/** 文字提示 */
const Tooltip = defineComponent({
  name: 'UiAntTooltip',
  inheritAttrs: false,
  props: {
    content: { type: String, default: '' },
    placement: { type: String, default: 'top' },
    trigger: { type: String, default: 'hover' },
    effect: { type: String, default: 'dark' },
    disabled: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots }) {
    return () => h(ATooltip as Component, {
      ...attrs,
      title: slots.content ?? props.content,
      open: props.disabled ? false : (attrs.open as boolean | undefined),
      placement: antPlacement(props.placement),
      trigger: props.trigger,
      color: props.effect === 'light' ? '#fff' : undefined,
      overlayInnerStyle: props.effect === 'light' ? { color: 'var(--text)' } : undefined,
    }, { default: slots.default })
  },
})

/** 下拉菜单：items → menu.items，divided → divider */
const Dropdown = defineComponent({
  name: 'UiAntDropdown',
  inheritAttrs: false,
  props: {
    items: { type: Array as PropType<UiDropdownItem[]>, default: () => [] },
    trigger: { type: String, default: 'click' },
    placement: { type: String, default: 'bottom' },
    disabled: { type: Boolean, default: false },
  },
  emits: ['select'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const items = props.items.map((item) => item.divided
        ? { type: 'divider' as const, key: item.key }
        : { key: item.key, label: item.label, danger: item.danger, disabled: item.disabled })
      return h(ADropdown as Component, {
        ...attrs,
        trigger: props.trigger,
        placement: antPlacement(props.placement),
        disabled: props.disabled,
        menu: {
          items,
          onClick: (info: { key: string | number }) => emit('select', String(info.key)),
        },
      }, { default: slots.default })
    }
  },
})

/** 二次确认 */
const Popconfirm = defineComponent({
  name: 'UiAntPopconfirm',
  inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    confirmText: { type: String, default: '确定' },
    cancelText: { type: String, default: '取消' },
    trigger: { type: String, default: 'click' },
    // antd 的 a-popconfirm 没有 width，契约里的 width 忽略
    width: { type: [String, Number], default: undefined },
  },
  emits: ['confirm', 'cancel'],
  setup(props, { attrs, slots, emit }) {
    return () => h(APopconfirm as Component, {
      ...attrs,
      title: props.title,
      okText: props.confirmText,
      cancelText: props.cancelText,
      trigger: props.trigger,
      onConfirm: (e: MouseEvent) => emit('confirm', e),
      onCancel: (e: MouseEvent) => emit('cancel', e),
    }, { default: slots.default })
  },
})

/** 骨架屏：loading=false 且有默认插槽时渲染真实内容 */
const Skeleton = defineComponent({
  name: 'UiAntSkeleton',
  inheritAttrs: false,
  props: {
    rows: { type: Number, default: 4 },
    animated: { type: Boolean, default: true },
    loading: { type: Boolean, default: true },
  },
  setup(props, { attrs, slots }) {
    return () => {
      if (!props.loading && slots.default) return slots.default()
      return h(ASkeleton as Component, {
        ...attrs,
        loading: props.loading,
        active: props.animated,
        paragraph: { rows: props.rows },
      })
    }
  },
})

const PROGRESS_STATUS: Record<string, string> = {
  ok: 'success', success: 'success',
  warn: 'normal', warning: 'normal',
  danger: 'exception', error: 'exception',
  info: 'normal', default: 'normal',
}

/** 进度条：status 语义别名 → antd status；showText → showInfo */
const Progress = defineComponent({
  name: 'UiAntProgress',
  inheritAttrs: false,
  props: {
    percentage: { type: Number, default: 0 },
    status: { type: String, default: undefined },
    strokeWidth: { type: Number, default: undefined },
    type: { type: String, default: 'line' },
    showText: { type: Boolean, default: true },
    color: { type: String, default: undefined },
  },
  setup(props, { attrs }) {
    return () => {
      const warn = props.status === 'warn' || props.status === 'warning'
      const status = props.status ? PROGRESS_STATUS[props.status] : undefined
      return h(AProgress as Component, {
        ...attrs,
        percent: Math.max(0, Math.min(100, props.percentage)),
        type: props.type === 'circle' ? 'circle' : 'line',
        status,
        strokeWidth: props.strokeWidth,
        showInfo: props.showText,
        strokeColor: props.color ?? (warn ? 'var(--warn)' : undefined),
      })
    }
  },
})

const ALERT_TYPE: Record<string, string> = {
  ok: 'success', success: 'success',
  warn: 'warning', warning: 'warning',
  danger: 'error', error: 'error',
  info: 'info', default: 'info',
  accent: 'info', primary: 'info',
  plain: 'info',
}

/** 警告条：type 语义别名 → antd type */
const Alert = defineComponent({
  name: 'UiAntAlert',
  inheritAttrs: false,
  props: {
    type: { type: String, default: 'info' },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    closable: { type: Boolean, default: true },
    showIcon: { type: Boolean, default: true },
  },
  emits: ['close'],
  setup(props, { attrs, slots, emit }) {
    return () => {
      const useSlot = Boolean(slots.default) && !props.description
      const userClose = attrs.onClose
      return h(AAlert as Component, {
        ...attrs,
        type: ALERT_TYPE[props.type] ?? 'info',
        message: props.title,
        description: useSlot ? undefined : props.description,
        showIcon: props.showIcon,
        closable: props.closable,
        onClose: (e: MouseEvent) => {
          if (typeof userClose === 'function') (userClose as (ev: MouseEvent) => void)(e)
          emit('close')
        },
      }, useSlot ? { default: slots.default } : undefined)
    }
  },
})

/** 分割线：contentPosition → orientation，borderStyle → dashed */
const Divider = defineComponent({
  name: 'UiAntDivider',
  inheritAttrs: false,
  props: {
    vertical: { type: Boolean, default: false },
    contentPosition: { type: String, default: 'center' },
    borderStyle: { type: String, default: 'solid' },
  },
  setup(props, { attrs, slots }) {
    return () => h(ADivider as Component, {
      ...attrs,
      type: props.vertical ? 'vertical' : 'horizontal',
      orientation: props.contentPosition === 'left' ? 'left' : props.contentPosition === 'right' ? 'right' : 'center',
      dashed: props.borderStyle === 'dashed',
    }, slots)
  },
})

/** 空状态 */
const Empty = defineComponent({
  name: 'UiAntEmpty',
  inheritAttrs: false,
  props: {
    description: { type: String, default: '' },
    size: { type: String, default: undefined },
    image: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    return () => h(AEmpty as Component, {
      ...attrs,
      description: props.description || undefined,
      image: props.image === 'none' ? undefined : props.image,
      imageStyle: props.image === 'none' ? { display: 'none' } : undefined,
    }, slots)
  },
})

/** 图标：antd v4 没有 a-icon，包一层 span 透传 svg */
const Icon = defineComponent({
  name: 'UiAntIcon',
  inheritAttrs: false,
  props: {
    size: { type: [Number, String], default: undefined },
    color: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    const userStyle = attrs.style as string | Record<string, string> | undefined
    return () => {
      const size = typeof props.size === 'number' ? props.size + 'px' : props.size
      const base: Record<string, string> = {}
      if (size) base.fontSize = size
      if (props.color) base.color = props.color
      const merged: string | Record<string, string> | undefined = typeof userStyle === 'string'
        ? userStyle + (size ? ';font-size:' + size : '') + (props.color ? ';color:' + props.color : '')
        : { ...base, ...(userStyle ?? {}) }
      return h('span', {
        ...attrs,
        class: ['ui-ant-icon', attrs.class as string | undefined],
        style: merged,
      }, slots.default?.())
    }
  },
})

/**
 * 根 Provider：a-config-provider 挂 zh-CN locale + 主题 token。
 * token.colorPrimary 跟 themeTokens.accent，明暗切 algorithm，applyTheme 后实时生效。
 */
const Root = defineComponent({
  name: 'UiAntRoot',
  setup(_props, { slots }) {
    const themeConfig = computed(() => ({
      token: {
        colorPrimary: themeTokens.value.accent,
        borderRadius: 4,
        fontFamily: 'inherit',
      },
      algorithm: themeTokens.value.dark ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
    }))
    return () => [
      h('style', { 'data-ui-shim': 'antd' }, SHIM_CSS),
      h(AConfigProvider as Component, { locale: zhCN, theme: themeConfig.value }, {
        default: () => slots.default?.(),
      }),
    ]
  },
})

const adapter: UiAdapter = {
  id: 'antd',
  name: 'Ant Design Vue',
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
    DescriptionItem: ADescriptionsItem as Component,
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
    Avatar: AAvatar as Component,
    Icon,
  },
}

export default adapter
