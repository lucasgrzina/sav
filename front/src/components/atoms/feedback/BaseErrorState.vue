<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  message?: string
  showRetry?: boolean
}>(), {
  title: 'Error al cargar',
  message: 'Ocurrió un problema. Por favor intente nuevamente.',
  showRetry: true,
})

const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-4 py-12">
    <a-result status="error" :title="title" :sub-title="message">
      <template #extra>
        <a-button v-if="showRetry" type="primary" @click="emit('retry')">
          Reintentar
        </a-button>
      </template>
    </a-result>
  </div>
</template>
