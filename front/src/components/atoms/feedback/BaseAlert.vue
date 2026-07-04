<script setup lang="ts">
withDefaults(defineProps<{
  type?: 'success' | 'info' | 'warning' | 'error'
  message: string
  description?: string
  closable?: boolean
  showIcon?: boolean
}>(), {
  type: 'info',
  closable: false,
  showIcon: true,
})
</script>

<template>
  <a-alert
    :type="type"
    :message="message"
    :description="description"
    :closable="closable"
    :show-icon="showIcon"
  />
</template>
