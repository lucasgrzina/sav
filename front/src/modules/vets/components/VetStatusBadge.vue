<script setup lang="ts">
import { computed } from 'vue'
import { VET_STATUS_LABELS, VET_STATUS_COLORS } from '../types/vet.enums'
import type { VetStatus } from '../types/vet.types'

const props = defineProps<{ status: VetStatus }>()

const color = computed(() => VET_STATUS_COLORS[props.status])
const label = computed(() => VET_STATUS_LABELS[props.status])
</script>

<template>
  <a-tag :color="color">{{ label }}</a-tag>
</template>
