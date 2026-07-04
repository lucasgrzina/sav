<script setup lang="ts">
import { computed } from 'vue'
import type { TechniqueType } from '../types/technique.types'

const props = defineProps<{ type: TechniqueType }>()

const color = computed(() => {
  const map: Record<TechniqueType, string> = {
    technique: 'blue',
    vaccine: 'green',
  }
  return map[props.type]
})

const label = computed(() => {
  const map: Record<TechniqueType, string> = {
    technique: 'Técnica',
    vaccine: 'Vacuna',
  }
  return map[props.type]
})
</script>

<template>
  <a-tag :color="color">{{ label }}</a-tag>
</template>
