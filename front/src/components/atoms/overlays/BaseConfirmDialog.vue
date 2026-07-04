<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  message?: string
  okText?: string
  cancelText?: string
  danger?: boolean
  loading?: boolean
}>(), {
  title: 'Confirmar acción',
  message: '¿Está seguro que desea continuar?',
  okText: 'Confirmar',
  cancelText: 'Cancelar',
  danger: false,
  loading: false,
})

const isOpen = defineModel<boolean>({ default: false })
const emit = defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <a-modal
    v-model:open="isOpen"
    :title="title"
    :ok-text="okText"
    :cancel-text="cancelText"
    :ok-button-props="{ danger: danger, loading: loading }"
    @ok="emit('confirm')"
    @cancel="emit('cancel')"
    :mask-closable="false"
  >
    <p>{{ message }}</p>
  </a-modal>
</template>
