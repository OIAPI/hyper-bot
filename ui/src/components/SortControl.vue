<script setup lang="ts">
/**
 * 排序条：点一次升序、再点降序、第三次取消。
 * 用 chips 而不是下拉，手机上点起来更快，也不依赖各组件库的排序事件。
 */
import type { SortState } from '@/utils/sortRows'

interface SortOption {
  key: string
  label: string
}

const props = defineProps<{
  options: SortOption[]
  modelValue: SortState | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: SortState | null): void
}>()

function active(key: string): boolean {
  return props.modelValue?.key === key
}

function toggle(key: string): void {
  const cur = props.modelValue
  if (!cur || cur.key !== key) {
    emit('update:modelValue', { key, dir: 'asc' })
    return
  }
  if (cur.dir === 'asc') {
    emit('update:modelValue', { key, dir: 'desc' })
    return
  }
  emit('update:modelValue', null)
}
</script>

<template>
  <div class="sort-control">
    <span class="sort-label">排序</span>
    <button
      v-for="opt in options"
      :key="opt.key"
      type="button"
      class="sort-chip"
      :class="{ on: active(opt.key) }"
      @click="toggle(opt.key)"
    >
      {{ opt.label }}
      <span v-if="active(opt.key)" class="sort-arrow">{{ modelValue?.dir === 'asc' ? '↑' : '↓' }}</span>
    </button>
  </div>
</template>

<style scoped>
.sort-control {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.sort-label {
  font-size: 11.5px;
  color: var(--text-faint);
}
.sort-chip {
  height: 24px;
  padding: 0 9px;
  border-radius: 999px;
  border: 1px solid var(--border-soft);
  background: transparent;
  color: var(--text-dim);
  font-size: 11.5px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.sort-chip:hover { color: var(--text); border-color: var(--border); }
.sort-chip.on {
  color: var(--accent);
  border-color: var(--accent);
  background: color-mix(in srgb, var(--accent) 8%, transparent);
}
.sort-arrow { font-size: 10.5px; }
</style>
