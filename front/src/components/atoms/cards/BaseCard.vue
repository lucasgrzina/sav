<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  loading?: boolean
  bordered?: boolean
}>(), {
  loading: false,
  bordered: true,
})
</script>

<template>
  <a-card :title="title" :loading="loading" :bordered="bordered">
    <template v-if="$slots.header" #title>
      <slot name="header" />
    </template>
    <template v-if="$slots.extra" #extra>
      <slot name="extra" />
    </template>
    <slot />
    <template v-if="$slots.footer" #actions>
      <slot name="footer" />
    </template>
  </a-card>
</template>
