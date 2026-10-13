<script setup lang="ts">
import BaseTag from '@/components/atoms/display/BaseTag.vue'
import { STATE_COLOR, STATE_LABEL } from '../../../constants/alert-copy'
import type { AlertState } from '../../../types/program-alerts.types'

defineProps<{ state: AlertState }>()
</script>

<template>
  <BaseTag :color="STATE_COLOR[state]">{{ STATE_LABEL[state] }}</BaseTag>
</template>
