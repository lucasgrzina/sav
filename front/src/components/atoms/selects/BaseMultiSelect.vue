<script setup lang="ts">
import type { SelectOption } from '@/core/types/ui.types'

withDefaults(defineProps<{
  modelValue?: (string | number)[]
  options?: SelectOption[]
  loading?: boolean
  allowClear?: boolean
  placeholder?: string
  maxTagCount?: number | 'responsive'
}>(), {
  modelValue: () => [],
  options: () => [],
  loading: false,
  allowClear: true,
  placeholder: 'Seleccionar...',
  maxTagCount: 'responsive',
})

const emit = defineEmits<{ 'update:modelValue': [value: (string | number)[]] }>()
</script>

<template>
  <a-select
    v-bind="$attrs"
    :value="modelValue"
    :options="options"
    :loading="loading"
    :allow-clear="allowClear"
    :placeholder="placeholder"
    :max-tag-count="maxTagCount"
    mode="multiple"
    style="width: 100%"
    @change="emit('update:modelValue', $event)"
  />
</template>
