<script setup lang="ts">
withDefaults(defineProps<{
  modelValue?: string
  placeholder?: string
  size?: 'small' | 'middle' | 'large'
  prefix?: string
  suffix?: string
  status?: 'error' | 'warning'
  disabled?: boolean
}>(), {
  modelValue: '',
  placeholder: '',
  size: 'middle',
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <a-input
    v-bind="$attrs"
    :value="modelValue"
    :placeholder="placeholder"
    :size="size"
    :prefix="prefix"
    :suffix="suffix"
    :status="status"
    :disabled="disabled"
    @change="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
