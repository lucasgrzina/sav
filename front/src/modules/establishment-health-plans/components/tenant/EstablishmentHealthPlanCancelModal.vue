<script setup lang="ts">
import { computed } from 'vue'
import BaseConfirmDialog from '@/components/atoms/overlays/BaseConfirmDialog.vue'
import type { EstablishmentHealthPlanCancelTarget } from '../../types/establishment-health-plan.types'

const props = defineProps<{
  plan: EstablishmentHealthPlanCancelTarget | null
  isPending: boolean
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const visible = defineModel<boolean>({ required: true })

const message = computed(() =>
  props.plan
    ? `¿Estás seguro de que querés cancelar el plan sanitario de ${props.plan.client.name} en ${props.plan.establishment.name}? Esta acción no se puede deshacer.`
    : '',
)
</script>

<template>
  <BaseConfirmDialog
    v-model="visible"
    title="Cancelar plan sanitario"
    :message="message"
    ok-text="Cancelar plan"
    cancel-text="Volver"
    danger
    :loading="isPending"
    @confirm="emit('confirm')"
    @cancel="emit('cancel')"
  />
</template>
