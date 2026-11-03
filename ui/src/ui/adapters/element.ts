// 只引实际用到的组件样式：整包 index.css 364KB，按需后约 1/3
import 'element-plus/es/components/base/style/css'
import 'element-plus/es/components/alert/style/css'
import 'element-plus/es/components/avatar/style/css'
import 'element-plus/es/components/button/style/css'
import 'element-plus/es/components/card/style/css'
import 'element-plus/es/components/checkbox/style/css'
import 'element-plus/es/components/descriptions/style/css'
import 'element-plus/es/components/dialog/style/css'
import 'element-plus/es/components/divider/style/css'
import 'element-plus/es/components/dropdown/style/css'
import 'element-plus/es/components/empty/style/css'
import 'element-plus/es/components/form/style/css'
import 'element-plus/es/components/icon/style/css'
import 'element-plus/es/components/input/style/css'
import 'element-plus/es/components/input-number/style/css'
import 'element-plus/es/components/popconfirm/style/css'
import 'element-plus/es/components/popover/style/css'
import 'element-plus/es/components/progress/style/css'
import 'element-plus/es/components/radio/style/css'
import 'element-plus/es/components/segmented/style/css'
import 'element-plus/es/components/select/style/css'
import 'element-plus/es/components/skeleton/style/css'
import 'element-plus/es/components/slider/style/css'
import 'element-plus/es/components/switch/style/css'
import 'element-plus/es/components/table/style/css'
import 'element-plus/es/components/tag/style/css'
import 'element-plus/es/components/tooltip/style/css'
import 'element-plus/theme-chalk/dark/css-vars.css'
import { defineComponent, h, ref, type PropType } from 'vue'
import {
  ElAlert, ElAvatar, ElButton, ElCard, ElCheckbox, ElDescriptions, ElDescriptionsItem,
  ElDialog, ElDivider, ElDropdown, ElDropdownItem, ElEmpty, ElForm, ElFormItem, ElIcon,
  ElInput, ElInputNumber, ElOption, ElPopconfirm, ElPopover, ElProgress, ElRadio, ElRadioGroup,
  ElSegmented, ElSelect, ElSkeleton, ElSlider, ElSwitch, ElTable, ElTableColumn, ElTag, ElTooltip,
} from 'element-plus'
import type { UiAdapter, UiColumn, UiDropdownItem, UiOption, UiThemeTokens } from '../types'

function parseHex(hex: string): [number, number, number] {
  const s = hex.replace('#', '')
  const n = parseInt(s.length === 3 ? s.split('').map((c) => c + c).join('') : s, 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
function mix(hex: string, target: string, w: number): string {
  const a = parseHex(hex)
  const b = parseHex(target)
  const m = (x: number, y: number) => Math.round(x + (y - x) * w)
  return '#' + [m(a[0], b[0]), m(a[1], b[1]), m(a[2], b[2])].map((v) => v.toString(16).padStart(2, '0')).join('')
}

/** 语义别名 -> Element 合法值（视图里写 ok/warn/accent 这种人话，适配器负责翻译） */
const ALERT_TYPE: Record<string, string> = {
  ok: 'success', success: 'success', warn: 'warning', warning: 'warning',
  danger: 'error', error: 'error', info: 'info', accent: 'info', primary: 'info', plain: 'info',
}
const PROGRESS_STATUS: Record<string, string> = {
  ok: 'success', success: 'success', warn: 'warning', warning: 'warning',
  danger: 'exception', error: 'exception', info: 'normal', accent: 'normal', plain: 'normal',
}
const TAG_TYPE: Record<string, string | undefined> = {
  ok: 'success', success: 'success', warn: 'warning', warning: 'warning',
  danger: 'danger', error: 'danger', info: 'info',
  accent: undefined, primary: undefined, plain: undefined, default: undefined,
}

/** 标签：语义别名 + 圆角/描边 */
const Tag = defineComponent({
  name: 'UiElTag', inheritAttrs: false,
  props: {
    type: { type: String, default: 'default' },
    size: { type: String, default: 'default' },
    effect: { type: String, default: 'light' },
    round: { type: Boolean, default: false },
    plain: { type: Boolean, default: false },
    closable: { type: Boolean, default: false },
  },
  emits: ['close'],
  setup(props, { attrs, slots, emit }) {
    return () => h(ElTag as any, {
      ...attrs,
      type: TAG_TYPE[props.type],
      size: props.size === 'default' ? undefined : props.size,
      effect: props.plain ? 'plain' : props.effect,
      round: props.round,
      closable: props.closable,
      onClose: () => emit('close'),
    }, slots)
  },
})

/** 卡片：标题 + 右侧操作区 */
const Card = defineComponent({
  name: 'UiElCard', inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    padding: { type: String, default: undefined },
  },
  setup(props, { attrs, slots }) {
    const bodyStyle = (): Record<string, string> | undefined => {
      if (props.padding === 'none') return { padding: '0' }
      if (props.padding === 'small') return { padding: '12px 14px' }
      return undefined
    }
    return () => {
      const hasHead = Boolean(props.title || props.subtitle || slots.extra || slots.title || slots.header)
      const children: Record<string, unknown> = { default: slots.default }
      // 两种写法都支持：契约推荐 title/#extra，老页面写 #header 也照样渲染
      if (slots.header) children.header = slots.header
      else if (hasHead) children.header = () =>
        h('div', { class: 'ui-card-head' }, [
          h('div', { class: 'ui-card-head-main' }, slots.title
            ? slots.title()
            : [
                props.title ? h('div', { class: 'ui-card-title' }, props.title) : null,
                props.subtitle ? h('div', { class: 'ui-card-subtitle' }, props.subtitle) : null,
              ]),
          slots.extra ? h('div', { class: 'ui-card-extra' }, slots.extra()) : null,
        ])
      if (slots.footer) children.footer = slots.footer
      return h(ElCard as any, { ...attrs, shadow: (attrs as any).shadow ?? 'never', bodyStyle: bodyStyle() }, children as any)
    }
  },
})

/** 输入框：补一个 @enter */
const Input = defineComponent({
  name: 'UiElInput', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    type: { type: String, default: 'text' },
  },
  emits: ['update:modelValue', 'enter', 'clear'],
  setup(props, { attrs, slots, emit }) {
    return () =>
      h(ElInput as any, {
        ...attrs,
        type: props.type === 'textarea' ? 'textarea' : props.type === 'password' ? 'password' : 'text',
        modelValue: props.modelValue,
        'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
        onKeyup: (e: KeyboardEvent) => {
          const own = (attrs as any).onKeyup
          if (typeof own === 'function') own(e)
          if (e.key === 'Enter') emit('enter')
        },
        onClear: () => emit('clear'),
      }, slots as any)
  },
})

/** 下拉选择：options -> el-option */
const Select = defineComponent({
  name: 'UiElSelect', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number, Array] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
    placeholder: { type: String, default: '' },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { slots, attrs, emit }) {
    return () =>
      h(ElSelect as any, {
        ...attrs,
        modelValue: props.modelValue,
        placeholder: props.placeholder,
        'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
        onChange: (v: unknown) => emit('change', v),
      }, {
        default: () => props.options.map((o) =>
          h(ElOption, { key: o.value, label: o.label, value: o.value, disabled: o.disabled })),
        ...(slots.prefix ? { prefix: slots.prefix } : {}),
      })
  },
})

/** 单选组 */
const RadioGroup = defineComponent({
  name: 'UiElRadioGroup', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () =>
      h(ElRadioGroup as any, {
        ...attrs,
        modelValue: props.modelValue,
        'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
        onChange: (v: unknown) => emit('change', v),
      }, {
        default: () => props.options.map((o) =>
          h(ElRadio, { key: o.value, value: o.value, disabled: o.disabled }, () => o.label)),
      })
  },
})

/** 表格：columns + #cell-<key> */
const Table = defineComponent({
  name: 'UiElTable', inheritAttrs: false,
  props: {
    data: { type: Array as PropType<Record<string, unknown>[]>, default: () => [] },
    columns: { type: Array as PropType<UiColumn[]>, default: () => [] },
    rowKey: { type: [String, Function], default: undefined },
    emptyText: { type: String, default: undefined },
    maxHeight: { type: [String, Number], default: undefined },
  },
  emits: ['row-click'],
  setup(props, { slots, attrs, emit }) {
    return () =>
      h(ElTable as any, {
        ...attrs,
        data: props.data,
        rowKey: props.rowKey,
        emptyText: props.emptyText,
        maxHeight: props.maxHeight,
        onRowClick: (row: unknown) => emit('row-click', row),
      }, {
        default: () => props.columns.map((col) => {
          const cell = slots['cell-' + col.key]
          return h(ElTableColumn as any, {
            key: col.key,
            prop: col.key,
            label: col.title,
            width: col.width,
            minWidth: col.minWidth,
            align: col.align,
            showOverflowTooltip: col.ellipsis,
          }, cell ? {
            default: (scope: { row: Record<string, unknown>; $index: number }) =>
              cell({ row: scope.row, index: scope.$index, value: scope.row[col.key] }),
          } : undefined)
        }),
        empty: slots.empty,
        append: slots.append,
      })
  },
})

/** 表单：validate() -> Promise<boolean> */
const Form = defineComponent({
  name: 'UiElForm', inheritAttrs: false,
  props: {
    model: { type: Object as PropType<Record<string, unknown>>, default: undefined },
    rules: { type: Object as PropType<Record<string, unknown>>, default: undefined },
    labelWidth: { type: [String, Number], default: undefined },
    labelPosition: { type: String, default: undefined },
    inline: { type: Boolean, default: false },
  },
  setup(props, { slots, attrs, expose }) {
    const inner = ref<any>()
    expose({
      validate: async (): Promise<boolean> => {
        try {
          const ok = await inner.value?.validate()
          return ok !== false
        } catch {
          return false
        }
      },
      resetFields: () => inner.value?.resetFields(),
      clearValidate: () => inner.value?.clearValidate(),
    })
    return () => h(ElForm as any, {
      ...attrs,
      ref: inner,
      model: props.model,
      rules: props.rules,
      labelWidth: props.labelWidth,
      labelPosition: props.labelPosition,
      inline: props.inline,
    }, slots as any)
  },
})

/** 勾选框 */
const Checkbox = defineComponent({
  name: 'UiElCheckbox', inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { slots, attrs, emit }) {
    return () => h(ElCheckbox, {
      ...attrs,
      modelValue: props.modelValue,
      disabled: props.disabled,
      'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    }, { default: slots.default ?? (() => props.label) })
  },
})

/** 分段控件 */
const Segmented = defineComponent({
  name: 'UiElSegmented', inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number] as PropType<unknown>, default: undefined },
    options: { type: Array as PropType<UiOption[]>, default: () => [] },
  },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () => h(ElSegmented as any, {
      ...attrs,
      modelValue: props.modelValue,
      options: props.options,
      'onUpdate:modelValue': (v: unknown) => emit('update:modelValue', v),
      onChange: (v: unknown) => emit('change', v),
    })
  },
})

/** 骨架屏 */
const Skeleton = defineComponent({
  name: 'UiElSkeleton', inheritAttrs: false,
  props: {
    rows: { type: Number, default: 5 },
    animated: { type: Boolean, default: true },
    loading: { type: Boolean, default: true },
  },
  setup(props, { attrs, slots }) {
    return () => {
      if (!props.loading && slots.default) return slots.default()
      return h(ElSkeleton as any, { ...attrs, rows: props.rows, animated: props.animated })
    }
  },
})

/** 提示条 */
const Alert = defineComponent({
  name: 'UiElAlert', inheritAttrs: false,
  props: {
    type: { type: String, default: 'info' },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
  },
  setup(props, { attrs, slots }) {
    return () => h(ElAlert as any, {
      ...attrs,
      type: ALERT_TYPE[props.type] ?? props.type,
      title: props.title,
      description: slots.default && !props.description ? undefined : props.description,
    }, slots.default ? { default: slots.default } : undefined)
  },
})

/** 进度条 */
const Progress = defineComponent({
  name: 'UiElProgress', inheritAttrs: false,
  props: {
    percentage: { type: Number, default: 0 },
    status: { type: String, default: undefined },
    type: { type: String, default: 'line' },
  },
  setup(props, { attrs, slots }) {
    return () => h(ElProgress as any, {
      ...attrs,
      // 统一夹到 0-100：不同库对越界百分比的处理不一致（有的直接显示 4000%）
      percentage: Math.max(0, Math.min(100, Number(props.percentage) || 0)),
      type: props.type,
      status: props.status ? (PROGRESS_STATUS[props.status] ?? props.status) : undefined,
    }, slots.default ? { default: slots.default } : undefined)
  },
})

/** 描述列表：items -> el-descriptions-item；也支持默认插槽（与其他库对齐） */
const Descriptions = defineComponent({
  name: 'UiElDescriptions',
  inheritAttrs: false,
  props: {
    items: { type: Array as PropType<{ label: string; value?: unknown; span?: number }[]>, default: () => [] },
    column: { type: Number, default: 3 },
    border: { type: Boolean, default: false },
  },
  setup(props, { attrs, slots }) {
    return () => h(ElDescriptions as any, {
      ...attrs,
      column: props.column,
      border: props.border || Boolean((attrs as any).border),
    }, {
      default: () => slots.default
        ? slots.default()
        : props.items.map((item, i) => h(ElDescriptionsItem as any, {
            key: item.label + '-' + i,
            label: item.label,
            span: item.span,
          }, () => String(item.value ?? '—'))),
    })
  },
})

/** 气泡确认 */
const Popconfirm = defineComponent({
  name: 'UiElPopconfirm', inheritAttrs: false,
  props: {
    title: { type: String, default: '' },
    confirmText: { type: String, default: '确定' },
    cancelText: { type: String, default: '取消' },
    width: { type: [String, Number], default: 220 },
  },
  emits: ['confirm', 'cancel'],
  setup(props, { attrs, slots, emit }) {
    return () => h(ElPopconfirm as any, {
      ...attrs,
      title: props.title,
      confirmButtonText: props.confirmText,
      cancelButtonText: props.cancelText,
      width: props.width,
      onConfirm: () => emit('confirm'),
      onCancel: () => emit('cancel'),
    }, { reference: slots.default })
  },
})

/** 下拉菜单 */
const Dropdown = defineComponent({
  name: 'UiElDropdown', inheritAttrs: false,
  props: {
    items: { type: Array as PropType<UiDropdownItem[]>, default: () => [] },
    trigger: { type: String, default: 'click' },
    placement: { type: String, default: 'bottom-end' },
  },
  emits: ['select'],
  setup(props, { attrs, slots, emit }) {
    return () => h(ElDropdown as any, {
      ...attrs,
      trigger: props.trigger,
      placement: props.placement,
      onCommand: (key: string) => emit('select', key),
    }, {
      default: slots.default,
      dropdown: () => props.items.map((item) => h(ElDropdownItem as any, {
        key: item.key,
        command: item.key,
        disabled: item.disabled,
        divided: item.divided,
        class: item.danger ? 'is-danger' : undefined,
      }, () => item.label)),
    })
  },
})

/** 气泡卡片 */
const Popover = defineComponent({
  name: 'UiElPopover', inheritAttrs: false,
  props: {
    modelValue: { type: Boolean, default: false },
    width: { type: [String, Number], default: 240 },
    placement: { type: String, default: 'bottom' },
    trigger: { type: String, default: 'click' },
  },
  emits: ['update:modelValue'],
  setup(props, { slots, attrs, emit }) {
    return () => h(ElPopover as any, {
      ...attrs,
      visible: props.modelValue,
      width: props.width,
      placement: props.placement,
      trigger: props.trigger,
      'onUpdate:visible': (v: boolean) => emit('update:modelValue', v),
    }, { reference: slots.default, default: slots.content })
  },
})

const adapter: UiAdapter = {
  id: 'element',
  name: 'Element Plus',
  components: {
    Card,
    Button: ElButton,
    Tag,
    Input,
    InputNumber: ElInputNumber,
    Select,
    Switch: ElSwitch,
    Checkbox,
    RadioGroup,
    Segmented,
    Slider: ElSlider,
    Form,
    FormItem: ElFormItem,
    Table,
    Descriptions,
    DescriptionItem: ElDescriptionsItem,
    Dialog: ElDialog,
    Empty: ElEmpty,
    Skeleton,
    Progress,
    Alert,
    Divider: ElDivider,
    Tooltip: ElTooltip,
    Popover,
    Dropdown,
    Popconfirm,
    Avatar: ElAvatar,
    Icon: ElIcon,
  },
  applyTheme(tokens: UiThemeTokens) {
    const root = document.documentElement
    const bg = tokens.dark ? '#141414' : '#ffffff'
    root.style.setProperty('--el-color-primary', tokens.accent)
    for (const lv of [3, 5, 7, 8, 9]) {
      root.style.setProperty('--el-color-primary-light-' + lv, mix(tokens.accent, bg, lv / 10))
    }
    root.style.setProperty('--el-color-primary-dark-2', mix(tokens.accent, '#000000', 0.2))
  },
}

export default adapter