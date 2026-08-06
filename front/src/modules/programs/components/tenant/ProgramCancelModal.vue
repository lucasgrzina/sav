<script setup lang="ts">
import { computed } from 'vue'
import BaseConfirmDialog from '@/components/atoms/overlays/BaseConfirmDialog.vue'
import type { ProgramCancelTarget } from '../../types/program.types'

const props = defineProps<{
  program: ProgramCancelTarget | null
  isPending: boolean
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const visible = defineModel<boolean>({ required: true })

const message = computed(() =>
  props.program
    ? `¿Estás seguro de que querés cancelar el programa de ${props.program.client.name} en ${props.program.establishment.name}? Esta acción no se puede deshacer.`
    : '',
)
</script>

<template>
  <BaseConfirmDialog
    v-model="visible"
    title="Cancelar programa"
    :message="message"
    ok-text="Cancelar programa"
    cancel-text="Volver"
    danger
    :loading="isPending"
    @confirm="emit('confirm')"
    @cancel="emit('cancel')"
  />
</template>
