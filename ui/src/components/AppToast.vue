<script setup lang="ts">
/**
 * 全局提示条（挂在 AppLayout 上，store 驱动）。
 *
 * 观感对齐 Element Plus 的 message：右上角堆叠、按类型取 EP 语义色、
 * 圆角/阴影/过渡都走项目令牌。窄屏改居中铺满，避免在手机上贴边不好点。
 * 接口不变：只读 useToastStore() 的 items / dismiss，点击任意一条即关闭。
 */
import { useToastStore } from '@/stores/toast'
import type { ToastKind } from '@/stores/toast'

const toast = useToastStore()

/** 用字形图标，不给这个全局挂件引入组件注册依赖 */
const ICONS: Record<ToastKind, string> = {
  ok: '✓',
  error: '✕',
  warn: '!',
  info: 'i',
}
</script>

<template>
  <div class="toast-wrap" aria-live="polite">
    <TransitionGroup name="toast">
      <div
        v-for="t in toast.items"
        :key="t.id"
        class="toast"
        :class="t.kind"
        title="点击关闭"
        @click="toast.dismiss(t.id)"
      >
        <span class="toast-icon" aria-hidden="true">{{ ICONS[t.kind] }}</span>
        <span class="toast-msg">{{ t.message }}</span>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-wrap {
  position: fixed;
  top: 16px;
  right: 16px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
  max-width: min(420px, calc(100vw - 24px));
  pointer-events: none;
}

.toast {
  /* 每种类型只换这两个变量，其余样式共用 */
  --toast-color: var(--el-color-info);
  display: flex;
  align-items: flex-start;
  gap: 10px;
  max-width: 100%;
  padding: 10px 14px;
  border: 1px solid color-mix(in srgb, var(--toast-color) 26%, var(--el-border-color-lighter));
  border-radius: var(--radius-lg);
  background: color-mix(in srgb, var(--toast-color) 9%, var(--el-bg-color-overlay));
  box-shadow: var(--shadow-lg);
  color: var(--el-text-color-primary);
  font-size: 13px;
  line-height: 1.5;
  cursor: pointer;
  word-break: break-word;
  pointer-events: auto;
  transition: border-color 160ms var(--ease), box-shadow 160ms var(--ease);
}
.toast:hover {
  border-color: color-mix(in srgb, var(--toast-color) 52%, var(--el-border-color));
  box-shadow: var(--shadow-lg), var(--shadow-glow);
}
.toast.ok {
  --toast-color: var(--el-color-success);
}
.toast.error {
  --toast-color: var(--el-color-danger);
}
.toast.warn {
  --toast-color: var(--el-color-warning);
}
.toast.info {
  --toast-color: var(--el-color-info);
}

.toast-icon {
  flex: none;
  width: 18px;
  height: 18px;
  margin-top: 1px;
  border-radius: var(--radius-full);
  background: var(--toast-color);
  color: var(--text-inverse);
  display: grid;
  place-items: center;
  font-size: 10.5px;
  font-weight: 700;
  line-height: 1;
}

.toast-msg {
  flex: 1;
  min-width: 0;
}

/* 160ms 进出场；移动中的条目用 move 过渡，删掉一条时其余平滑补位 */
.toast-enter-active,
.toast-leave-active {
  transition: opacity 160ms var(--ease), transform 160ms var(--ease);
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateX(16px) scale(0.97);
}
.toast-move {
  transition: transform 160ms var(--ease);
}

/* 窄屏：居中铺满，别贴着右边缘 */
@media (max-width: 640px) {
  .toast-wrap {
    top: 12px;
    left: 12px;
    right: 12px;
    max-width: none;
    align-items: center;
  }
  .toast {
    width: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .toast-enter-active,
  .toast-leave-active,
  .toast-move {
    transition-duration: 1ms;
  }
}
</style>
