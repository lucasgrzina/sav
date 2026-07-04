<script setup lang="ts">
withDefaults(defineProps<{
  color?: string
  closable?: boolean
}>(), {
  closable: false,
})

const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <a-tag :color="color" :closable="closable" @close="emit('close')">
    <slot />
  </a-tag>
</template>
