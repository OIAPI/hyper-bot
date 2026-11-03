import { defineComponent, h, ref, type Component } from 'vue'
import { useUiPart } from './provider'
import type { UiPart } from './types'

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
      return () => {
        const comp = current.value
        if (!comp) return null
        return h(comp as Component, { ...attrs, ref: inner }, slots)
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
export const UiTable = part('Table')
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