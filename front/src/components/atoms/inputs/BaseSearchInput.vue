<script setup lang="ts">
withDefaults(defineProps<{
  modelValue?: string
  placeholder?: string
  loading?: boolean
}>(), {
  modelValue: '',
  placeholder: 'Buscar...',
  loading: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  search: [value: string]
}>()
</script>

<template>
  <a-input-search
    v-bind="$attrs"
    :value="modelValue"
    :placeholder="placeholder"
    :loading="loading"
    allow-clear
    @change="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    @search="emit('search', $event)"
  />
</template>
