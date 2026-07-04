<script setup lang="ts">
withDefaults(defineProps<{
  modelValue?: string
  placeholder?: string
  size?: 'small' | 'middle' | 'large'
}>(), {
  modelValue: '',
  placeholder: '',
  size: 'middle',
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <a-input-password
    v-bind="$attrs"
    :value="modelValue"
    :placeholder="placeholder"
    :size="size"
    @change="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
