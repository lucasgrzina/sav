<script setup lang="ts">
withDefaults(defineProps<{
  status?: 'success' | 'processing' | 'error' | 'default' | 'warning'
  text?: string
  count?: number
  dot?: boolean
}>(), {
  status: 'default',
  dot: false,
})
</script>

<template>
  <a-badge :status="status" :text="text" :count="count" :dot="dot" />
</template>
