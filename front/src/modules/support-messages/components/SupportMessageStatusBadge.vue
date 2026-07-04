<script setup lang="ts">
import { computed } from 'vue'
import type { SupportMessageStatus } from '../types/support-message.types'

const props = defineProps<{ status: SupportMessageStatus }>()

const color = computed(() => {
  const map: Record<SupportMessageStatus, string> = {
    open: 'blue',
    in_progress: 'orange',
    closed: 'green',
  }
  return map[props.status]
})

const label = computed(() => {
  const map: Record<SupportMessageStatus, string> = {
    open: 'Abierto',
    in_progress: 'En proceso',
    closed: 'Cerrado',
  }
  return map[props.status]
})
</script>

<template>
  <a-tag :color="color">{{ label }}</a-tag>
</template>
