<script setup lang="ts">
withDefaults(defineProps<{
  modelValue?: string
  placeholder?: string
  autoSize?: { minRows?: number; maxRows?: number } | boolean
}>(), {
  modelValue: '',
  placeholder: '',
  autoSize: () => ({ minRows: 3, maxRows: 6 }),
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <a-textarea
    v-bind="$attrs"
    :value="modelValue"
    :placeholder="placeholder"
    :auto-size="autoSize"
    @change="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
  />
</template>
