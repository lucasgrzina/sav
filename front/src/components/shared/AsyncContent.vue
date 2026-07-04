<script setup lang="ts">
import BaseSkeleton from '@/components/atoms/feedback/BaseSkeleton.vue'
import BaseErrorState from '@/components/atoms/feedback/BaseErrorState.vue'
import BaseEmptyState from '@/components/atoms/feedback/BaseEmptyState.vue'

withDefaults(defineProps<{
  loading?: boolean
  error?: Error | null
  isEmpty?: boolean
  errorMessage?: string
  emptyDescription?: string
  skeletonProps?: Record<string, unknown>
}>(), {
  loading: false,
  error: null,
  isEmpty: false,
  errorMessage: 'Error al cargar los datos.',
  emptyDescription: 'No hay datos para mostrar.',
  skeletonProps: () => ({}),
})

const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <BaseSkeleton v-if="loading" :loading="true" v-bind="skeletonProps">
    <div />
  </BaseSkeleton>
  <BaseErrorState v-else-if="error" :message="errorMessage" @retry="emit('retry')" />
  <BaseEmptyState v-else-if="isEmpty" :description="emptyDescription" />
  <slot v-else />
</template>
