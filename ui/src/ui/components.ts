import { defineComponent, h, nextTick, onMounted, ref, watch, type Component, type PropType } from 'vue'
import { useUiPart } from './provider'
import type { SortState } from '@/utils/sortRows'
import type { UiColumn, UiPart } from './types'

/**
 * 一层薄壳：把视图里的 UiXxx 解析成当前框架的同名组件。
 * 属性 / 事件 / 插槽全部原样透传，视图只写一次。
 */
function part(name: UiPart): Component {
  return defineComponent({
    name: 'Ui' + name,
    inheritAttrs: false,
    setup(_props, { slots, attrs, expose }) {
      const current = useUiPart(name)
      const inner = ref<Record<string, unknown> | null>(null)
      /**
       * 把真实组件的 expose 原样透出去，否则 <UiForm ref="f"> 上拿不到 validate()。
       * 用一个 Proxy 转发任意方法/属性，各适配器不用为壳组件额外声明。
       */
      expose(
        new Proxy(
          {},
          {
            get(_target, key: string) {
              const inst = inner.value as Record<string, unknown> | null
              if (!inst) return undefined
              const value = inst[key]
              return typeof value === 'function' ? (value as () => unknown).bind(inst) : value
            },
            has: () => true,
          },
        ),
      )
      /**
       * 选择器的点击不能再往外冒泡：视图里常见 <label class="field"> 包着 UiSelect，
       * 浏览器的 label 激活行为会把这次点击再转给里面的 input，el-select 于是
       * 刚展开就被第二次 click 收起。在壳层截断，所有视图一次修好。
       */
      const clickGuard =
        name === 'Select'
          ? (e: MouseEvent) => {
              const own = attrs.onClick as ((ev: MouseEvent) => void) | undefined
              if (typeof own === 'function') own(e)
              e.stopPropagation()
            }
          : undefined

      return () => {
        const comp = current.value
        if (!comp) return null
        return h(
          comp as Component,
          { ...attrs, ref: inner, ...(clickGuard ? { onClick: clickGuard } : {}) },
          slots,
        )
      }
    },
  })
}

export const UiCard = part('Card')
export const UiButton = part('Button')
export const UiTag = part('Tag')
export const UiInput = part('Input')
export const UiInputNumber = part('InputNumber')
export const UiSelect = part('Select')
export const UiSwitch = part('Switch')
export const UiCheckbox = part('Checkbox')
export const UiRadioGroup = part('RadioGroup')
export const UiSegmented = part('Segmented')
export const UiSlider = part('Slider')
export const UiForm = part('Form')
export const UiFormItem = part('FormItem')
/**
 * 表格壳：透传组件库 Table，同时接管「点表头排序」。
 *
 * 四个组件库的排序 API 各不相同（Element 的 sort-change、Antd 的 sorter、
 * Naive 的 update:sorter、Arco 的 sorter-change），与其写四份，不如统一在
 * DOM 层处理：点击 th 按列序号找到 column.key，三态循环（升 → 降 → 取消），
 * 用 data-sort 属性画箭头。视图只写 :sort / @update:sort。
 */
const Table = defineComponent({
  name: 'UiTable',
  inheritAttrs: false,
  props: {
    columns: { type: Array as PropType<UiColumn[]>, default: () => [] },
    sort: { type: Object as PropType<SortState | null>, default: null },
    /** 列 key → 排序 key（列 key 与排序字段不一致时用） */
    sortKeys: { type: Object as PropType<Record<string, string>>, default: () => ({}) },
  },
  emits: ['update:sort'],
  setup(props, { attrs, slots, emit }) {
    const current = useUiPart('Table')
    const root = ref<HTMLElement | null>(null)
    /** 操作列不参与排序 */
    const SKIP = new Set(['ops', 'actions'])

    function sortKeyOf(col: UiColumn | undefined): string {
      if (!col || col.key === '' || SKIP.has(col.key)) return ''
      return props.sortKeys[col.key] ?? col.key
    }

    function headerIndexOf(th: HTMLElement): string | null {
      const row = th.parentElement
      if (!row) return null
      const index = Array.from(row.children).indexOf(th)
      const key = sortKeyOf(props.columns[index])
      return key === '' ? null : key
    }

    function onRootClick(event: MouseEvent): void {
      const target = event.target as HTMLElement | null
      const th = target?.closest<HTMLElement>('th, [role="columnheader"]')
      if (!th) return
      const key = headerIndexOf(th)
      if (key === null) return
      const cur = props.sort
      if (!cur || cur.key !== key) emit('update:sort', { key, dir: 'asc' })
      else if (cur.dir === 'asc') emit('update:sort', { key, dir: 'desc' })
      else emit('update:sort', null)
    }

    function paint(): void {
      const host = root.value
      if (!host) return
      host.querySelectorAll<HTMLElement>('th, [role="columnheader"]').forEach((th) => {
        // 标记挂在「表头文字容器」上，箭头才能紧跟文字；挂在 th 上会被排到最右侧
        const cell = th.querySelector<HTMLElement>(
          '.cell, .ant-table-column-title, .n-data-table-th__title, .arco-table-th-item-title',
        ) ?? th
        th.classList.remove('ui-th-sortable')
        th.removeAttribute('data-sort')
        cell.classList.remove('ui-th-sortable')
        cell.removeAttribute('data-sort')
        const key = headerIndexOf(th)
        if (key === null) {
          th.style.cursor = ''
          return
        }
        th.style.cursor = 'pointer'
        cell.classList.add('ui-th-sortable')
        if (props.sort?.key === key) {
          cell.setAttribute('data-sort', props.sort.dir === 'asc' ? 'asc' : 'desc')
        }
      })
    }

    watch(() => props.sort, () => nextTick(paint))
    watch(() => props.columns, () => nextTick(paint))
    onMounted(() => {
      paint()
      // 表头是异步渲染的：补几次即可。不用 MutationObserver / onUpdated——
      // 之前那版在表头里插节点 + observer 会形成「改 DOM → 再标记 → 再改 DOM」的
      // 死循环，整页直接卡死。
      window.setTimeout(paint, 80)
      window.setTimeout(paint, 400)
      window.setTimeout(paint, 1200)
    })

    return () => {
      const comp = current.value
      if (!comp) return null
      const { onClick: ownClick, ...rest } = attrs as Record<string, unknown> & { onClick?: (e: MouseEvent) => void }
      return h('div', {
        class: 'ui-table',
        ref: root,
        onClick: (e: MouseEvent) => {
          ownClick?.(e)
          onRootClick(e)
        },
      }, [h(comp as Component, { ...rest, columns: props.columns }, slots)])
    }
  },
})

export const UiTable = Table
export const UiDescriptions = part('Descriptions')
export const UiDescriptionItem = part('DescriptionItem')
export const UiDialog = part('Dialog')
export const UiEmpty = part('Empty')
export const UiSkeleton = part('Skeleton')
export const UiProgress = part('Progress')
export const UiAlert = part('Alert')
export const UiDivider = part('Divider')
export const UiTooltip = part('Tooltip')
export const UiPopover = part('Popover')
export const UiDropdown = part('Dropdown')
export const UiPopconfirm = part('Popconfirm')
export const UiAvatar = part('Avatar')
export const UiIcon = part('Icon')
/** 全局注册用：名字与 UiPart 一致 */
export const UI_COMPONENTS: Record<string, Component> = {
  Card: UiCard,
  Button: UiButton,
  Tag: UiTag,
  Input: UiInput,
  InputNumber: UiInputNumber,
  Select: UiSelect,
  Switch: UiSwitch,
  Checkbox: UiCheckbox,
  RadioGroup: UiRadioGroup,
  Segmented: UiSegmented,
  Slider: UiSlider,
  Form: UiForm,
  FormItem: UiFormItem,
  Table: UiTable,
  Descriptions: UiDescriptions,
  DescriptionItem: UiDescriptionItem,
  Dialog: UiDialog,
  Empty: UiEmpty,
  Skeleton: UiSkeleton,
  Progress: UiProgress,
  Alert: UiAlert,
  Divider: UiDivider,
  Tooltip: UiTooltip,
  Popover: UiPopover,
  Dropdown: UiDropdown,
  Popconfirm: UiPopconfirm,
  Avatar: UiAvatar,
  Icon: UiIcon,
}