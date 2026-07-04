<script setup lang="ts">
withDefaults(defineProps<{
  size?: 'small' | 'default' | 'large'
  tip?: string
}>(), {
  size: 'default',
})
</script>

<template>
  <a-spin :size="size" :tip="tip">
    <slot />
  </a-spin>
</template>
