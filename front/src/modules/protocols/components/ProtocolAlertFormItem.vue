<script setup lang="ts">
import { computed } from 'vue'
import { ArrowUpOutlined, ArrowDownOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { getRoleLabel } from '@/core/utils/roles'
import type { TenantRole } from '../types/protocol.types'
import type { ProtocolTaskAlertFormValues } from '../validators/protocol.validator'

const props = defineProps<{
  alert: ProtocolTaskAlertFormValues
  index: number
  isFirst: boolean
  isLast: boolean
}>()

const emit = defineEmits<{
  update: [index: number, value: ProtocolTaskAlertFormValues]
  remove: [index: number]
  moveUp: [index: number]
  moveDown: [index: number]
}>()

const tenantRoles: TenantRole[] = [
  'vet',
  'vet-assistant',
  'vet-administrative',
  'client-owner',
  'client-manager',
  'client-administrative',
]

const roleOptions = tenantRoles.map((role) => ({ value: role, label: getRoleLabel(role) }))

function update<K extends keyof ProtocolTaskAlertFormValues>(
  field: K,
  value: ProtocolTaskAlertFormValues[K],
) {
  emit('update', props.index, { ...props.alert, [field]: value })
}

const rolesEmpty = computed(() => props.alert.roles.length === 0)
</script>

<template>
  <div class="pafi-root">
    <div class="pafi-row">
      <a-tooltip title="Cantidad de días respecto a la fecha de la tarea. 0 = mismo día. La dirección (antes/después) se elige en el selector de al lado.">
        <a-input-number
          :value="alert.offset_days"
          :min="0"
          placeholder="Días de diferencia"
          style="width: 100%"
          @update:value="(v: number | string | null) => update('offset_days', Number(v ?? 0))"
        />
      </a-tooltip>
      <a-tooltip title="Dirección respecto a la fecha de la tarea: antes o después.">
        <a-select
          :value="alert.time_of_day"
          style="width: 100%"
          @change="(v: unknown) => update('time_of_day', v as ProtocolTaskAlertFormValues['time_of_day'])"
        >
          <a-select-option value="before">Antes</a-select-option>
          <a-select-option value="after">Después</a-select-option>
        </a-select>
      </a-tooltip>
      <a-time-picker
        :value="alert.time || undefined"
        value-format="HH:mm"
        format="HH:mm"
        style="width: 100%"
        @update:value="(v: string) => update('time', v ?? '')"
      />
    </div>

    <a-select
      :value="alert.roles"
      :options="roleOptions"
      mode="multiple"
      placeholder="Roles que reciben la alerta *"
      :status="rolesEmpty ? 'error' : undefined"
      style="width: 100%"
      @change="(v: unknown) => update('roles', (v ?? []) as ProtocolTaskAlertFormValues['roles'])"
    />

    <a-textarea
      :value="alert.message"
      placeholder="Mensaje de la alerta *"
      :rows="2"
      @update:value="(v: string) => update('message', v)"
    />

    <div class="pafi-row pafi-row--footer">
      <a-checkbox
        :checked="alert.require_confirmation"
        @update:checked="(v: boolean) => update('require_confirmation', v)"
      >
        Requiere confirmación
      </a-checkbox>

      <div class="pafi-actions">
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
          tooltip="Eliminar alerta"
          @click="emit('remove', index)"
        >
          <template #icon><DeleteOutlined /></template>
        </BaseButton>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pafi-root {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px;
  border: 1px dashed var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 8px;
}

.pafi-row {
  display: flex;
  gap: 8px;
}

.pafi-row--footer {
  align-items: center;
  justify-content: space-between;
}

.pafi-actions {
  display: flex;
  gap: 4px;
}
</style>
