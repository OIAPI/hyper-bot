<script setup lang="ts">
import { onMounted } from 'vue'
import { useThemeStore } from '@/stores/theme'
import UiRoot from '@/ui/UiRoot.vue'

const theme = useThemeStore()
onMounted(() => theme.apply())
</script>

<template>
  <UiRoot>
    <RouterView />
  </UiRoot>
</template>
