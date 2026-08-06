<script setup lang="ts">
import { ArrowUpOutlined, ArrowDownOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ProtocolAlertList from './ProtocolAlertList.vue'
import type { ProtocolTaskFormValues } from '../validators/protocol.validator'

const props = defineProps<{
  task: ProtocolTaskFormValues
  index: number
  isFirst: boolean
  isLast: boolean
}>()

const emit = defineEmits<{
  update: [index: number, value: ProtocolTaskFormValues]
  remove: [index: number]
  moveUp: [index: number]
  moveDown: [index: number]
}>()

function update<K extends keyof ProtocolTaskFormValues>(field: K, value: ProtocolTaskFormValues[K]) {
  emit('update', props.index, { ...props.task, [field]: value })
}

function updateAlerts(value: ProtocolTaskFormValues['alerts']) {
  update('alerts', value)
}
</script>

<template>
  <div class="ptfi-root">
    <div class="ptfi-header">
      <span class="ptfi-title">Tarea {{ index + 1 }}</span>
      <div class="ptfi-actions">
        <BaseButton
          variant="row-action"
          size="small"
          :disabled="isFirst"
          tooltip="Subir"
          @click="emit('moveUp', index)"
        >
          <template #icon><ArrowUpOutlined /></template>
        </BaseButton>
        <BaseButton
          variant="row-action"
          size="small"
          :disabled="isLast"
          tooltip="Bajar"
          @click="emit('moveDown', index)"
        >
          <template #icon><ArrowDownOutlined /></template>
        </BaseButton>
        <BaseButton
          variant="row-action"
          size="small"
          danger
          tooltip="Eliminar tarea"
          @click="emit('remove', index)"
        >
          <template #icon><DeleteOutlined /></template>
        </BaseButton>
      </div>
    </div>

    <a-textarea
      :value="task.description"
      placeholder="Descripción de la tarea *"
      :rows="2"
      @update:value="(v: string) => update('description', v)"
    />

    <div class="ptfi-row">
      <a-tooltip title="Cantidad de días respecto a la fecha objetivo de la sub-técnica. 0 = mismo día. La dirección (antes/después) se elige en el selector de al lado.">
        <a-input-number
          :value="task.days_offset"
          :min="0"
          placeholder="Días de diferencia"
          style="width: 100%"
          @update:value="(v: number | string | null) => update('days_offset', Number(v ?? 0))"
        />
      </a-tooltip>
      <a-tooltip title="Dirección respecto a la fecha objetivo: antes o después.">
        <a-select
          :value="task.time_of_day"
          style="width: 100%"
          @change="(v: unknown) => update('time_of_day', v as ProtocolTaskFormValues['time_of_day'])"
        >
          <a-select-option value="before">Antes</a-select-option>
          <a-select-option value="after">Después</a-select-option>
        </a-select>
      </a-tooltip>
      <a-time-picker
        :value="task.time || undefined"
        value-format="HH:mm"
        format="HH:mm"
        style="width: 100%"
        @update:value="(v: string) => update('time', v ?? '')"
      />
    </div>

    <a-checkbox
      :checked="task.important"
      @update:checked="(v: boolean) => update('important', v)"
    >
      Tarea importante
    </a-checkbox>

    <div class="ptfi-alerts">
      <p class="ptfi-alerts-label">Alertas</p>
      <ProtocolAlertList :model-value="task.alerts" @update:model-value="updateAlerts" />
    </div>
  </div>
</template>

<style scoped>
.ptfi-root {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 10px;
}

.ptfi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.ptfi-title {
  font-weight: 600;
}

.ptfi-actions {
  display: flex;
  gap: 4px;
}

.ptfi-row {
  display: flex;
  gap: 8px;
}

.ptfi-alerts {
  margin-top: 4px;
}

.ptfi-alerts-label {
  font-size: 12px;
  color: var(--dt-muted, #6b8cae);
  margin: 0 0 6px;
}
</style>
