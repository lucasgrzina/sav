<script setup lang="ts">
import type { Dayjs } from 'dayjs'

withDefaults(defineProps<{
  modelValue?: [Dayjs | null, Dayjs | null] | null
  format?: string
  placeholder?: [string, string]
}>(), {
  format: 'DD/MM/YYYY',
  placeholder: () => ['Fecha inicio', 'Fecha fin'],
})

const emit = defineEmits<{ 'update:modelValue': [value: [Dayjs | null, Dayjs | null] | null] }>()
</script>

<template>
  <a-range-picker
    v-bind="$attrs"
    :value="modelValue"
    :format="format"
    :placeholder="placeholder"
    style="width: 100%"
    @change="emit('update:modelValue', $event)"
  />
</template>
