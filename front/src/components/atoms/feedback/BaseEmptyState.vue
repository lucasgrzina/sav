<script setup lang="ts">
import { Empty } from 'ant-design-vue'

withDefaults(defineProps<{
  description?: string
  image?: string
}>(), {
  description: 'Sin datos',
  image: Empty.PRESENTED_IMAGE_SIMPLE,
})
</script>

<template>
  <a-empty :description="description" :image="image">
    <template v-if="$slots.default" #description>
      <slot name="description">{{ description }}</slot>
    </template>
    <slot />
  </a-empty>
</template>
