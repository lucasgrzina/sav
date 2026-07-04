<script setup lang="ts">
import type { SelectOption } from '@/core/types/ui.types'

withDefaults(defineProps<{
  modelValue?: string | number | null
  options?: SelectOption[]
  loading?: boolean
  allowClear?: boolean
  placeholder?: string
}>(), {
  options: () => [],
  loading: false,
  allowClear: true,
  placeholder: 'Seleccionar...',
})

const emit = defineEmits<{ 'update:modelValue': [value: string | number | null] }>()
</script>

<template>
  <a-select
    v-bind="$attrs"
    :value="modelValue"
    :options="options"
    :loading="loading"
    :allow-clear="allowClear"
    :placeholder="placeholder"
    style="width: 100%"
    @change="emit('update:modelValue', $event)"
  />
</template>
