<script setup lang="ts">
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ProtocolAlertFormItem from './ProtocolAlertFormItem.vue'
import type { ProtocolTaskAlertFormValues } from '../validators/protocol.validator'

const alerts = defineModel<ProtocolTaskAlertFormValues[]>({ required: true })

function emptyAlert(): ProtocolTaskAlertFormValues {
  return {
    offset_days: 0,
    time_of_day: 'before',
    time: '',
    roles: [],
    message: '',
    require_confirmation: false,
  }
}

function addAlert() {
  alerts.value = [...alerts.value, emptyAlert()]
}

function removeAlert(index: number) {
  alerts.value = alerts.value.filter((_, i) => i !== index)
}

function updateAlert(index: number, value: ProtocolTaskAlertFormValues) {
  const updated = [...alerts.value]
  updated[index] = value
  alerts.value = updated
}

function moveUp(index: number) {
  if (index <= 0) return
  const updated = [...alerts.value]
  ;[updated[index - 1], updated[index]] = [updated[index], updated[index - 1]]
  alerts.value = updated
}

function moveDown(index: number) {
  if (index >= alerts.value.length - 1) return
  const updated = [...alerts.value]
  ;[updated[index], updated[index + 1]] = [updated[index + 1], updated[index]]
  alerts.value = updated
}
</script>

<template>
  <div class="pal-root">
    <p v-if="alerts.length === 0" class="pal-empty">Sin alertas para esta tarea.</p>

    <ProtocolAlertFormItem
      v-for="(alert, idx) in alerts"
      :key="alert.guid ?? `new-alert-${idx}`"
      :alert="alert"
      :index="idx"
      :is-first="idx === 0"
      :is-last="idx === alerts.length - 1"
      @update="updateAlert"
      @remove="removeAlert"
      @move-up="moveUp"
      @move-down="moveDown"
    />

    <BaseButton variant="secondary" size="small" @click="addAlert">
      <template #icon><PlusOutlined /></template>
      Agregar alerta
    </BaseButton>
  </div>
</template>

<style scoped>
.pal-root {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-left: 12px;
  border-left: 2px solid var(--dt-border, rgba(26, 229, 160, 0.12));
}

.pal-empty {
  font-size: 13px;
  color: var(--dt-muted, #6b8cae);
  margin: 0;
}
</style>
