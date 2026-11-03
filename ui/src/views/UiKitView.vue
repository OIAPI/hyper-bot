<script setup lang="ts">
/**
 * 组件一览 + 框架切换台。
 * 这一页是「框架无关层」的唯一权威示例：全部使用 UiXxx，不出现 el-*，
 * 所以顶部切换框架时整页控件会真的换成另一套组件库（不刷新页面）。
 */
import { computed, reactive, ref } from 'vue'
import { FRAMEWORKS, useThemeStore } from '@/stores/theme'
import { useUiRuntime } from '@/ui/provider'

const theme = useThemeStore()
const runtime = useUiRuntime()

const note = ref('')
const dialog = ref(false)
const popover = ref(false)
const formRef = ref<{ validate: () => Promise<boolean>; resetFields: () => void } | null>(null)

const form = reactive({ name: '摸鱼交流大群', level: 'normal', notify: true, rate: 30 })
const rules = {
  name: [{ required: true, message: '群名不能为空', trigger: 'blur' }],
}

const groups = [
  { id: '884726153', name: '摸鱼交流大群', members: 412, status: 'ok', plugin: 'Help' },
  { id: '193847201', name: '测试环境', members: 12, status: 'ok', plugin: 'Ping' },
  { id: '551029384', name: '摸鱼二群', members: 88, status: 'warn', plugin: '—' },
  { id: '774519203', name: '外部对接群', members: 6, status: 'danger', plugin: 'Webhook' },
]

const columns = [
  { key: 'name', title: '群名称', minWidth: 160 },
  { key: 'members', title: '成员', width: 80, align: 'right' as const },
  { key: 'status', title: '状态', width: 90 },
  { key: 'plugin', title: '绑定插件', width: 110 },
  { key: 'actions', title: '', width: 90, align: 'right' as const },
]

const statusText: Record<string, { text: string; type: string }> = {
  ok: { text: '正常', type: 'ok' },
  warn: { text: '限流', type: 'warn' },
  danger: { text: '异常', type: 'danger' },
}

const levelOptions = [
  { label: '宽松', value: 'loose' },
  { label: '常规', value: 'normal' },
  { label: '严格', value: 'strict' },
]

const descItems = computed(() => [
  { label: '当前框架', value: FRAMEWORKS.find((f) => f.id === theme.framework)?.name ?? '—' },
  { label: '加载状态', value: runtime.loading.value ? '切换中…' : '就绪' },
  { label: '明暗模式', value: theme.effective === 'dark' ? '深色' : '浅色' },
  { label: '主色', value: theme.accentHex },
])

async function submit(): Promise<void> {
  const ok = await formRef.value?.validate()
  note.value = ok ? '校验通过：' + form.name : '校验未通过：群名必填'
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>组件一览</h1>
        <p class="sub">
          同一套标记，四套真实组件库。切换即刻生效——不用刷新，留在这页看控件怎么变。
        </p>
      </div>
      <div class="frameworks">
        <button
          v-for="fw in FRAMEWORKS"
          :key="fw.id"
          class="fw"
          :class="{ active: theme.framework === fw.id }"
          type="button"
          @click="theme.setFramework(fw.id)"
        >
          <span class="fw-name">{{ fw.name }}</span>
          <span class="fw-vendor">{{ fw.vendor }}</span>
        </button>
      </div>
    </header>

    <UiAlert
      v-if="runtime.failed.value"
      type="danger"
      title="框架加载失败"
      :description="runtime.failed.value"
    />
    <p v-if="note" class="note">{{ note }}</p>

    <div class="grid">
      <UiCard class="span-2">
        <template #header><span class="card-title">按钮与标签</span></template>
        <div class="row wrap gap">
          <UiButton type="primary">主要操作</UiButton>
          <UiButton>次要操作</UiButton>
          <UiButton type="danger">危险操作</UiButton>
          <UiButton type="text">文字按钮</UiButton>
          <UiButton type="primary" plain>描边</UiButton>
          <UiButton type="primary" size="small">小号</UiButton>
          <UiButton type="primary" loading>加载中</UiButton>
          <UiButton disabled>禁用</UiButton>
        </div>
        <UiDivider />
        <div class="row wrap gap">
          <UiTag type="accent">accent</UiTag>
          <UiTag type="ok">正常</UiTag>
          <UiTag type="warn">限流</UiTag>
          <UiTag type="danger">异常</UiTag>
          <UiTag type="info">信息</UiTag>
          <UiTag type="plain">默认</UiTag>
        </div>
      </UiCard>

      <UiCard>
        <template #header><span class="card-title">输入控件</span></template>
        <div class="col gap">
          <UiInput v-model="form.name" placeholder="群名称" clearable />
          <UiInputNumber v-model="form.rate" :min="0" :max="120" />
          <UiSelect v-model="form.level" :options="levelOptions" />
          <UiCheckbox v-model="form.notify" label="开启通知" />
          <UiSegmented v-model="form.level" :options="levelOptions" />
          <UiSlider v-model="form.rate" :min="0" :max="120" />
          <div class="row between">
            <span class="muted">启用插件</span>
            <UiSwitch v-model="form.notify" />
          </div>
        </div>
      </UiCard>

      <UiCard>
        <template #header><span class="card-title">表单校验</span></template>
        <UiForm ref="formRef" :model="form" :rules="rules" label-width="72">
          <UiFormItem label="群名称" prop="name">
            <UiInput v-model="form.name" placeholder="必填" />
          </UiFormItem>
          <UiFormItem label="级别">
            <UiSelect v-model="form.level" :options="levelOptions" />
          </UiFormItem>
          <UiFormItem label="">
            <UiButton type="primary" @click="submit">提交校验</UiButton>
          </UiFormItem>
        </UiForm>
      </UiCard>

      <UiCard class="span-2">
        <template #header>
          <div class="row between">
            <span class="card-title">表格</span>
            <UiTooltip content="单元格通过 #cell-<key> 插槽自定义">
              <UiButton type="text" size="small">说明</UiButton>
            </UiTooltip>
          </div>
        </template>
        <UiTable :data="groups" :columns="columns" row-key="id" stripe>
          <template #cell-name="{ row }">
            <span class="strong">{{ row.name }}</span>
          </template>
          <template #cell-members="{ value }">
            <span class="mono">{{ value }}</span>
          </template>
          <template #cell-status="{ value }">
            <UiTag :type="statusText[value]?.type || 'info'">{{ statusText[value]?.text || value }}</UiTag>
          </template>
          <template #cell-actions="{ row }">
            <UiButton type="text" size="small" @click="note = '查看 ' + row.name">查看</UiButton>
          </template>
        </UiTable>
      </UiCard>

      <UiCard>
        <template #header><span class="card-title">描述列表</span></template>
        <UiDescriptions :items="descItems" :column="1" border />
      </UiCard>

      <UiCard>
        <template #header><span class="card-title">反馈</span></template>
        <div class="col gap">
          <UiAlert type="ok" title="连接正常" description="2 个协议在线，无异常。" />
          <UiAlert type="warn" title="限流提醒" description="群 551029384 触发频率限制。" />
          <UiProgress :percentage="72" />
          <UiProgress :percentage="38" status="warn" />
          <div class="row gap wrap">
            <UiTooltip content="这是提示气泡">
              <UiButton size="small">悬停</UiButton>
            </UiTooltip>
            <UiPopover v-model="popover" title="弹出卡片" width="240">
              <template #default>
                <UiButton size="small">点我弹出</UiButton>
              </template>
              <template #content>
                <p class="muted">插槽 #default 是触发器，#content 是内容，跨库一致。</p>
              </template>
            </UiPopover>
            <UiAvatar :size="28">摸</UiAvatar>
            <UiAvatar :size="28" src="" />
          </div>
        </div>
      </UiCard>

      <UiCard>
        <template #header><span class="card-title">其他</span></template>
        <div class="col gap">
          <UiButton type="primary" @click="dialog = true">打开弹窗</UiButton>
          <UiSkeleton :rows="3" animated />
          <UiEmpty description="暂无数据" />
        </div>
      </UiCard>
    </div>

    <UiDialog v-model="dialog" title="弹窗" width="420">
      <p class="muted">弹窗内容同样来自当前框架，底部按钮区走 #footer 插槽。</p>
      <UiInput v-model="form.name" placeholder="随便写点" />
      <template #footer>
        <div class="row right gap">
          <UiButton @click="dialog = false">取消</UiButton>
          <UiButton type="primary" @click="dialog = false">确定</UiButton>
        </div>
      </template>
    </UiDialog>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
h1 { margin: 0; font-size: 19px; letter-spacing: -0.01em; }
.sub { margin: 4px 0 0; color: var(--text-faint); font-size: 12.5px; }
.frameworks { display: flex; gap: 6px; flex-wrap: wrap; }
.fw {
  display: flex; flex-direction: column; align-items: flex-start; gap: 1px;
  padding: 7px 11px; border-radius: var(--radius-sm); cursor: pointer;
  background: var(--bg-card); border: 1px solid var(--border); text-align: left;
  transition: border-color 160ms ease, background 160ms ease;
}
.fw:hover { border-color: var(--border-strong); }
.fw.active { border-color: var(--accent); background: var(--accent-soft); }
.fw-name { font-size: 12.5px; font-weight: 600; color: var(--text); }
.fw-vendor { font-size: 10.5px; color: var(--text-faint); letter-spacing: 0.04em; text-transform: uppercase; }
.note { margin: 0; font-size: 12.5px; color: var(--accent); }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; align-items: start; }
.span-2 { grid-column: span 2; }
.card-title { font-size: 13px; font-weight: 600; }
.row { display: flex; align-items: center; }
.col { display: flex; flex-direction: column; }
.wrap { flex-wrap: wrap; }
.gap { gap: 8px; }
.between { justify-content: space-between; }
.right { justify-content: flex-end; }
.strong { font-weight: 600; }
.mono { font-variant-numeric: tabular-nums; font-family: var(--font-mono); }
.muted { color: var(--text-faint); font-size: 12.5px; margin: 0; }
@media (max-width: 1100px) {
  .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .span-2 { grid-column: span 2; }
}
</style>