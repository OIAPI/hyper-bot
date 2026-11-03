<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useUiAdapter } from './provider'

/** 库级主题 Provider 的挂载点 */
const adapter = useUiAdapter()
const Root = computed<Component | null>(() => (adapter.value?.Root as Component | undefined) ?? null)
</script>

<template>
  <component :is="Root" v-if="Root"><slot /></component>
  <slot v-else />
</template>
