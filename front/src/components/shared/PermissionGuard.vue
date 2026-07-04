<script setup lang="ts">
import { computed } from 'vue'
import { usePermission } from '@/core/composables/usePermissions'

const props = defineProps<{
  permission: string | string[]
  requireAll?: boolean
}>()

const { can } = usePermission()

const hasPermission = computed(() => {
  if (Array.isArray(props.permission)) {
    return props.requireAll
      ? props.permission.every(p => can(p))
      : props.permission.some(p => can(p))
  }
  return can(props.permission)
})
</script>

<template>
  <slot v-if="hasPermission" />
  <slot v-else name="fallback" />
</template>
