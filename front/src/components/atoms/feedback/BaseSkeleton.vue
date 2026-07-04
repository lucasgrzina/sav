<script setup lang="ts">
withDefaults(defineProps<{
  loading?: boolean
  active?: boolean
  avatar?: boolean
  paragraph?: { rows?: number } | boolean
}>(), {
  loading: true,
  active: true,
  avatar: false,
  paragraph: () => ({ rows: 4 }),
})
</script>

<template>
  <a-skeleton
    :loading="loading"
    :active="active"
    :avatar="avatar"
    :paragraph="paragraph"
  >
    <slot />
  </a-skeleton>
</template>
