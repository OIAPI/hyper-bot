import { defineComponent, h, Teleport, type PropType } from 'vue'
import type { UiAdapter, UiColumn, UiOption } from '../types'
import './native.css'

/**
 * 轻量适配器：不依赖任何组件库，纯 HTML + CSS。
 *
 * 存在的理由不是「再做一个 UI 库」，而是首屏：
 * Element 的 adapter chunk 是 343KB JS + 364KB CSS，Ant 更是 851KB。
 * 这些包必须下载完才有控件可渲染，服务器带宽一般时就是几秒白屏。
 * native 只有几 KB，先用它把页面画出来，用户选中的组件库在后台下载完再无缝替换
 * （视图只认 UiXxx，换适配器不重挂组件、状态不丢）。
 */

const cx = (...parts: Array<string | false | undefined | null>): string => parts.filter(Boolean).join(' ')

function alignClass(col: UiColumn): string {
  return col.align ? 'uit-' + col.align : ''
}

function colStyle(col: UiColumn): Record<string, string> {
  const style: Record<string, string> = {}
  if (col.width) style.width = typeof col.width === 'number' ? col.width + 'px' : col.width
  if (col.minWidth) style.minWidth = typeof col.minWidth === 'number' ? col.minWidth + 'px' : col.minWidth
  return style
}

const Card = defineComponent({
  name: 'UiNativeCard',
  setup(_p, { slots }) {
    return () =>
      h('section', { class: 'uic' }, [
        slots.header ? h('header', { class: 'uic-h' }, slots.header()) : null,
        h('div', { class: 'uic-b' }, slots.default ? slots.default() : []),
      ])
  },
})

const Button = defineComponent({
  name: 'UiNativeButton',
  inheritAttrs: false,
  props: {
    type: { type: String, default: 'default' },
    size: { type: String, default: 'default' },
    text: Boolean,
    plain: Boolean,
    loading: Boolean,
    disabled: Boolean,
  },
  emits: ['click'],
  setup(props, { slots, attrs, emit }) {
    return () =>
      h('button', {
        ...attrs,
        type: 'button',
        class: cx('uib', 'uib-' + props.type, 'uib-s-' + props.size, props.text && 'uib-text', props.plain && 'uib-plain', attrs.class as string),
        disabled: props.disabled || props.loading,
        onClick: (e: MouseEvent) => emit('click', e),
      }, [props.loading ? h('i', { class: 'uib-spin' }) : null, slots.default ? slots.default() : []])
  },
})

const Tag = defineComponent({
  name: 'UiNativeTag',
  props: { type: { type: String, default: 'default' }, size: { type: String, default: 'default' } },
  setup(props, { slots }) {
    return () => h('span', { class: cx('uitag', 'uitag-' + props.type, 'uitag-s-' + props.size) }, slots.default ? slots.default() : [])
  },
})

const Input = defineComponent({
  name: 'UiNativeInput',
  inheritAttrs: false,
  props: { modelValue: { type: [String, Number], default: '' }, type: { type: String, default: 'text' }, disabled: Boolean },
  emits: ['update:modelValue', 'change'],
  setup(props, { attrs, emit }) {
    return () =>
      h('input', {
        ...attrs,
        class: cx('uiin', attrs.class as string),
        type: props.type,
        value: props.modelValue,
        disabled: props.disabled,
        onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value),
        onChange: (e: Event) => emit('change', (e.target as HTMLInputElement).value),
      })
  },
})

const InputNumber = defineComponent({
  name: 'UiNativeInputNumber',
  props: { modelValue: { type: Number, default: 0 }, min: Number, max: Number, step: { type: Number, default: 1 } },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h('input', {
        class: 'uiin uiin-num',
        type: 'number',
        value: props.modelValue,
        min: props.min,
        max: props.max,
        step: props.step,
        onInput: (e: Event) => emit('update:modelValue', Number((e.target as HTMLInputElement).value)),
      })
  },
})

const Select = defineComponent({
  name: 'UiNativeSelect',
  props: { modelValue: { type: [String, Number, Array], default: '' }, options: { type: Array as PropType<UiOption[]>, default: () => [] }, disabled: Boolean, size: String },
  emits: ['update:modelValue', 'change'],
  setup(props, { emit }) {
    return () =>
      h('select', {
        class: cx('uisel', props.size === 'small' && 'uisel-s'),
        disabled: props.disabled,
        value: String(props.modelValue ?? ''),
        onChange: (e: Event) => {
          const el = e.target as HTMLSelectElement
          const pick = props.options[el.selectedIndex]
          const value = pick ? pick.value : el.value
          emit('update:modelValue', value)
          emit('change', value)
        },
      }, props.options.map((o) =>
        h('option', { value: String(o.value), disabled: o.disabled }, o.label),
      ))
  },
})

const Switch = defineComponent({
  name: 'UiNativeSwitch',
  props: { modelValue: { type: [Boolean, String, Number], default: false }, disabled: Boolean },
  emits: ['update:modelValue', 'change'],
  setup(props, { emit }) {
    return () =>
      h('button', {
        type: 'button',
        role: 'switch',
        'aria-checked': Boolean(props.modelValue),
        class: cx('uisw', Boolean(props.modelValue) && 'uisw-on'),
        disabled: props.disabled,
        onClick: () => {
          const next = !props.modelValue
          emit('update:modelValue', next)
          emit('change', next)
        },
      }, [h('i', { class: 'uisw-dot' })])
  },
})

const Checkbox = defineComponent({
  name: 'UiNativeCheckbox',
  props: { modelValue: { type: [Boolean, Array], default: false }, label: String, disabled: Boolean },
  emits: ['update:modelValue'],
  setup(props, { emit, slots }) {
    return () =>
      h('label', { class: cx('uick', props.disabled && 'is-disabled') }, [
        h('input', {
          type: 'checkbox',
          checked: Boolean(props.modelValue),
          disabled: props.disabled,
          onChange: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).checked),
        }),
        h('span', { class: 'uick-t' }, props.label || (slots.default ? slots.default() : '')),
      ])
  },
})

const Segmented = defineComponent({
  name: 'UiNativeSegmented',
  props: { modelValue: { type: [String, Number], default: '' }, options: { type: Array as PropType<UiOption[]>, default: () => [] } },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h('div', { class: 'uiseg' }, props.options.map((o) =>
        h('button', {
          type: 'button',
          class: cx('uiseg-i', props.modelValue === o.value && 'is-on'),
          onClick: () => emit('update:modelValue', o.value),
        }, o.label),
      ))
  },
})

const Slider = defineComponent({
  name: 'UiNativeSlider',
  props: { modelValue: { type: Number, default: 0 }, min: { type: Number, default: 0 }, max: { type: Number, default: 100 }, step: { type: Number, default: 1 } },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h('input', {
        class: 'uisl',
        type: 'range',
        value: props.modelValue,
        min: props.min,
        max: props.max,
        step: props.step,
        onInput: (e: Event) => emit('update:modelValue', Number((e.target as HTMLInputElement).value)),
      })
  },
})

const Form = defineComponent({
  name: 'UiNativeForm',
  props: { labelPosition: { type: String, default: 'top' } },
  setup(props, { slots }) {
    return () => h('form', { class: cx('uif', 'uif-' + props.labelPosition), onSubmit: (e: Event) => e.preventDefault() }, slots.default ? slots.default() : [])
  },
})

const FormItem = defineComponent({
  name: 'UiNativeFormItem',
  props: { label: String },
  setup(props, { slots }) {
    return () =>
      h('div', { class: 'uifi' }, [
        props.label ? h('div', { class: 'uifi-l' }, props.label) : null,
        h('div', { class: 'uifi-c' }, slots.default ? slots.default() : []),
      ])
  },
})

const Table = defineComponent({
  name: 'UiNativeTable',
  inheritAttrs: false,
  props: {
    data: { type: Array as PropType<Record<string, unknown>[]>, default: () => [] },
    columns: { type: Array as PropType<UiColumn[]>, default: () => [] },
    rowKey: { type: [String, Function], default: undefined },
    emptyText: { type: String, default: undefined },
    loading: Boolean,
    size: String,
  },
  emits: ['row-click'],
  setup(props, { slots, attrs, emit }) {
    const keyOf = (row: Record<string, unknown>, i: number): string | number => {
      if (typeof props.rowKey === 'function') return (props.rowKey as (r: unknown) => string | number)(row)
      if (typeof props.rowKey === 'string' && row[props.rowKey] !== undefined) return row[props.rowKey] as string | number
      return i
    }
    const cell = (row: Record<string, unknown>, col: UiColumn) => {
      const slot = slots['cell-' + col.key]
      if (slot) return slot({ row })
      const v = row[col.key]
      if (v === undefined || v === null || v === '') return '—'
      if (typeof v === 'boolean') return v ? '是' : '否'
      return String(v)
    }
    return () =>
      h('div', { ...attrs, class: cx('uit-wrap', attrs.class as string) }, [
        h('table', { class: cx('uit', props.size === 'small' && 'uit-s') }, [
          h('thead', [h('tr', props.columns.map((c) =>
            h('th', { key: c.key, class: alignClass(c), style: colStyle(c) }, c.title),
          ))]),
          h('tbody', props.data.length === 0
            ? [h('tr', [h('td', { colspan: Math.max(1, props.columns.length), class: 'uit-empty' }, props.emptyText || '暂无数据')])]
            : props.data.map((row, i) =>
                h('tr', { key: keyOf(row, i), onClick: () => emit('row-click', row) },
                  props.columns.map((c) => h('td', { key: c.key, class: cx(alignClass(c), c.ellipsis && 'uit-ell'), style: colStyle(c) }, cell(row, c)))),
              )),
        ]),
      ])
  },
})

const Descriptions = defineComponent({
  name: 'UiNativeDescriptions',
  props: { items: { type: Array as PropType<Array<{ label: string; value: string }>>, default: () => [] }, column: { type: Number, default: 1 }, border: Boolean },
  setup(props, { slots }) {
    return () =>
      props.items.length
        ? h('dl', { class: cx('uidl', props.border && 'uidl-b', 'uidl-c' + props.column) },
            props.items.map((it) => h('div', { class: 'uidl-i' }, [
              h('dt', it.label),
              h('dd', it.value),
            ])))
        : h('div', { class: 'uidl-loose' }, slots.default ? slots.default() : [])
  },
})

const DescriptionItem = defineComponent({
  name: 'UiNativeDescriptionItem',
  props: { label: String },
  setup(props, { slots }) {
    return () => h('div', { class: 'uidl-i' }, [h('dt', props.label || ''), h('dd', slots.default ? slots.default() : [])])
  },
})

const Dialog = defineComponent({
  name: 'UiNativeDialog',
  props: { modelValue: Boolean, title: String, width: { type: String, default: '520px' } },
  emits: ['update:modelValue', 'close'],
  setup(props, { slots, emit }) {
    const close = (): void => {
      emit('update:modelValue', false)
      emit('close')
    }
    return () =>
      h(Teleport, { to: 'body' }, [
        props.modelValue
          ? h('div', { class: 'uid-mask', onClick: close }, [
              h('div', {
                class: 'uid',
                style: { width: props.width },
                onClick: (e: Event) => e.stopPropagation(),
              }, [
                h('div', { class: 'uid-h' }, [
                  h('span', { class: 'uid-t' }, props.title || ''),
                  h('button', { type: 'button', class: 'uid-x', onClick: close }, '×'),
                ]),
                h('div', { class: 'uid-b' }, slots.default ? slots.default() : []),
                slots.footer ? h('div', { class: 'uid-f' }, slots.footer()) : null,
              ]),
            ])
          : null,
      ])
  },
})

const Empty = defineComponent({
  name: 'UiNativeEmpty',
  props: { description: { type: String, default: '暂无数据' } },
  setup(props, { slots }) {
    return () => h('div', { class: 'uie' }, [h('div', { class: 'uie-d' }, props.description), slots.default ? h('div', { class: 'uie-x' }, slots.default()) : null])
  },
})

const Skeleton = defineComponent({
  name: 'UiNativeSkeleton',
  props: { rows: { type: Number, default: 3 } },
  setup(props) {
    return () => h('div', { class: 'uisk' }, Array.from({ length: props.rows }).map((_, i) => h('i', { key: i, class: 'uisk-r' })))
  },
})

const Progress = defineComponent({
  name: 'UiNativeProgress',
  props: { percentage: { type: Number, default: 0 }, percent: { type: Number, default: undefined }, status: String, showText: { type: Boolean, default: true } },
  setup(props) {
    const value = (): number => Math.max(0, Math.min(100, Number(props.percentage ?? props.percent ?? 0)))
    return () => h('div', { class: 'uip' }, [
      h('div', { class: 'uip-t' }, [h('i', { class: cx('uip-f', props.status && 'uip-' + props.status), style: { width: value() + '%' } })]),
      props.showText ? h('span', { class: 'uip-l' }, value().toFixed(0) + '%') : null,
    ])
  },
})

const Alert = defineComponent({
  name: 'UiNativeAlert',
  props: { type: { type: String, default: 'info' }, title: String, description: String, closable: { type: Boolean, default: true } },
  setup(props, { slots }) {
    return () =>
      h('div', { class: cx('uia', 'uia-' + props.type) }, [
        h('div', { class: 'uia-b' }, [
          props.title ? h('div', { class: 'uia-t' }, props.title) : null,
          h('div', { class: 'uia-d' }, props.description || (slots.default ? slots.default() : '')),
        ]),
      ])
  },
})

const Divider = defineComponent({
  name: 'UiNativeDivider',
  props: { direction: { type: String, default: 'horizontal' } },
  setup(props) {
    return () => h('hr', { class: cx('uidv', props.direction === 'vertical' && 'uidv-v') })
  },
})

const Tooltip = defineComponent({
  name: 'UiNativeTooltip',
  props: { content: { type: String, default: '' } },
  setup(props, { slots }) {
    return () => h('span', { class: 'uitip', title: props.content }, slots.default ? slots.default() : [])
  },
})

const Popover = defineComponent({
  name: 'UiNativePopover',
  props: { content: { type: String, default: '' }, title: String },
  setup(props, { slots }) {
    return () =>
      h('details', { class: 'uipop' }, [
        h('summary', props.title || '详情'),
        h('div', { class: 'uipop-b' }, props.content || (slots.default ? slots.default() : '')),
      ])
  },
})

const Avatar = defineComponent({
  name: 'UiNativeAvatar',
  props: { size: { type: [Number, String], default: 32 } },
  setup(props, { slots }) {
    const size = typeof props.size === 'number' ? props.size + 'px' : props.size
    return () => h('span', { class: 'uiav', style: { width: size, height: size } }, slots.default ? slots.default() : [])
  },
})

const Icon = defineComponent({
  name: 'UiNativeIcon',
  setup(_p, { slots }) {
    return () => h('span', { class: 'uii' }, slots.default ? slots.default() : [])
  },
})

const adapter: UiAdapter = {
  id: 'native',
  name: '轻量（无组件库）',
  components: {
    Card, Button, Tag, Input, InputNumber, Select, Switch, Checkbox, Segmented, Slider,
    Form, FormItem, Table, Descriptions, DescriptionItem, Dialog, Empty, Skeleton,
    Progress, Alert, Divider, Tooltip, Popover, Avatar, Icon,
  },
  applyTheme: ({ accent, dark }) => {
    const root = document.documentElement
    root.style.setProperty('--ui-accent', accent)
    root.dataset.nativeDark = dark ? '1' : '0'
  },
}

export default adapter