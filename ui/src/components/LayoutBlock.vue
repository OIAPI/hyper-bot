<script setup lang="ts">
/**
 * 页面布局块：编辑态显示拖动柄与隐藏按钮。
 * 样式（order/display）通过 attrs 落到根节点，所以外层直接用 :style。
 */
defineProps<{ id: string; label: string; editing: boolean; hidden?: boolean; narrow?: boolean }>()

const emit = defineEmits<{
  (e: 'drag-start', event: PointerEvent): void
  (e: 'toggle'): void
  (e: 'move-up'): void
  (e: 'move-down'): void
}>()
</script>

<template>
  <div class="layout-block" :class="{ off: hidden }" :data-block="id">
    <div v-if="editing" class="layout-handle" :class="{ narrow }">
      <template v-if="narrow">
        <button class="layout-move" type="button" title="上移" @click="emit('move-up')">↑</button>
        <button class="layout-move" type="button" title="下移" @click="emit('move-down')">↓</button>
      </template>
      <span v-else class="layout-grip" title="拖动排序" @pointerdown="emit('drag-start', $event)">⋮⋮</span>
      <span class="layout-name">{{ label }}</span>
      <span class="spacer" />
      <button class="layout-hide" type="button" @click="emit('toggle')">{{ hidden ? '显示' : '隐藏' }}</button>
    </div>
    <slot />
  </div>
</template>

<style scoped>
.layout-block { display: flex; flex-direction: column; gap: 6px; }
.layout-block.off { opacity: 0.45; }
.layout-handle {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border: 1px dashed var(--border);
  border-radius: var(--radius-sm);
  color: var(--text-faint);
  font-size: 11.5px;
  background: var(--bg-card);
}
.layout-grip { cursor: grab; touch-action: none; user-select: none; letter-spacing: -2px; }
.layout-name { color: var(--text-dim); }
.spacer { flex: 1; }
.layout-hide {
  border: 1px solid var(--border-soft);
  background: transparent;
  color: var(--text-faint);
  border-radius: 5px;
  height: 20px;
  padding: 0 6px;
  font-size: 10.5px;
  cursor: pointer;
}
.layout-hide:hover { color: var(--text); border-color: var(--border); }
.layout-move {
  width: 26px;
  height: 24px;
  border: 1px solid var(--border-soft);
  border-radius: 5px;
  background: transparent;
  color: var(--text-dim);
  font-size: 12px;
  cursor: pointer;
}
.layout-move:hover { color: var(--text); border-color: var(--border); }
.layout-handle.narrow { gap: 6px; }
</style>
