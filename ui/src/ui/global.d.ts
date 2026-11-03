import type {
  UiAlert, UiAvatar, UiButton, UiCard, UiCheckbox,
  UiDescriptionItem, UiDescriptions, UiDialog, UiDivider, UiEmpty,
  UiForm, UiFormItem, UiIcon, UiInput, UiInputNumber,
  UiPopover, UiProgress, UiSegmented, UiSelect, UiSkeleton, UiDropdown, UiPopconfirm, UiRadioGroup,
  UiSlider, UiSwitch, UiTable, UiTag, UiTooltip,
} from './components'

declare module 'vue' {
  export interface GlobalComponents {
    UiAlert: typeof UiAlert
    UiAvatar: typeof UiAvatar
    UiButton: typeof UiButton
    UiCard: typeof UiCard
    UiCheckbox: typeof UiCheckbox
    UiDescriptionItem: typeof UiDescriptionItem
    UiDescriptions: typeof UiDescriptions
    UiDialog: typeof UiDialog
    UiDivider: typeof UiDivider
    UiEmpty: typeof UiEmpty
    UiForm: typeof UiForm
    UiFormItem: typeof UiFormItem
    UiIcon: typeof UiIcon
    UiInput: typeof UiInput
    UiInputNumber: typeof UiInputNumber
    UiDropdown: typeof UiDropdown
    UiPopconfirm: typeof UiPopconfirm
    UiRadioGroup: typeof UiRadioGroup
    UiPopover: typeof UiPopover
    UiProgress: typeof UiProgress
    UiSegmented: typeof UiSegmented
    UiSelect: typeof UiSelect
    UiSkeleton: typeof UiSkeleton
    UiSlider: typeof UiSlider
    UiSwitch: typeof UiSwitch
    UiTable: typeof UiTable
    UiTag: typeof UiTag
    UiTooltip: typeof UiTooltip
  }
}

export {}
