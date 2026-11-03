# UiXxx 抽象层契约（视图只写这一套）

所有页面**只允许**使用 `src/ui/components.ts` 里的 `Ui*` 组件，不得再出现 `el-*`
（布局外壳 AppLayout / 登录页可以例外，但它们也会逐步迁移）。

## 为什么
`UiXxx` 是一层薄壳：运行时按当前框架（Element / Ant Design / Naive / Arco）解析成对应组件库，
属性与插槽原样透传。视图只写一次，切换框架即时生效，不刷新。

## 通用
- 事件用 `v-model` / `@update:modelValue`，各库适配器已统一。
- 属性名以本文件为准；某库没有对应属性时适配器会丢弃或降级。

## 各组件契约
| 组件 | 关键 props | 说明 |
| --- | --- | --- |
| UiButton | type: default\|primary\|danger\|text；size: small\|default\|large；plain、round、loading、disabled | `@click` |
| UiTag | type: ok\|warn\|danger\|info\|accent\|plain；effect: light\|plain\|dark；size、round | 语义名，不是库的配色名 |
| UiInput | v-model；placeholder、disabled、clearable、type、rows | `#prefix`/`#suffix` 可选 |
| UiInputNumber | v-model；min、max、step、precision、disabled | |
| UiSelect | v-model；:options="[{label,value,disabled}]"；placeholder、clearable、disabled | 不要写子节点 option |
| UiSwitch | v-model；disabled | |
| UiCheckbox | v-model；label、disabled | 文字走 label |
| UiSegmented | v-model；:options="[{label,value}]" | 分段控件 |
| UiSlider | v-model；min、max、step、disabled | 值可为 number 或 number[] |
| UiForm | :model、:rules、labelWidth、labelPosition、inline | 暴露 `validate(): Promise<boolean>` / resetFields() / clearValidate() |
| UiFormItem | label、prop、required | |
| UiTable | :data、:columns="[{key,title,width?,minWidth?,align?,ellipsis?}]"；size、stripe、height、rowKey、emptyText | 单元格用 `#cell-<key>="{ row, index, value }"` 插槽 |
| UiDescriptions | :items="[{label,value,span?}]"；column、border | 不要写 item 子组件 |
| UiDialog | v-model；title、width | `#default` 内容，`#footer` 按钮区；@closed |
| UiEmpty | description | |
| UiSkeleton | :rows、animated、loading | |
| UiProgress | :percentage、status、:strokeWidth | |
| UiAlert | type: ok\|warn\|danger\|info；title、description、closable | |
| UiDivider | — | |
| UiTooltip | content；placement | |
| UiPopover | v-model（可见）；title、width、placement、trigger | `#default` 触发器，`#content` 内容 |
| UiAvatar | :size、src | `#default` 文字头像 |
| UiIcon | — | `#default` 放 svg |

## 参考实现
各业务页面（如 `src/views/AccountView.vue`）就是使用示例；组件契约以本文件为准。