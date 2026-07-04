<script setup lang="ts">
import { computed } from 'vue'
import { USER_STATUS_COLORS, USER_STATUS_LABELS } from '../constants/users.constants'

const props = defineProps<{ status: string }>()

const color = computed(() => USER_STATUS_COLORS[props.status] ?? 'default')
const label = computed(() => USER_STATUS_LABELS[props.status] ?? props.status)
</script>

<template>
  <a-tag :color="color">{{ label }}</a-tag>
</template>
