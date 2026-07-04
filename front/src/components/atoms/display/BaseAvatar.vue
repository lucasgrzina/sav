<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  size?: number | 'small' | 'default' | 'large'
  src?: string
  name?: string
  shape?: 'circle' | 'square'
}>(), {
  size: 'default',
  shape: 'circle',
})

const initials = computed(() => {
  if (!props.name) return '?'
  return props.name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
})
</script>

<template>
  <a-avatar :size="size" :src="src" :shape="shape">
    {{ initials }}
  </a-avatar>
</template>
