<script setup lang="ts">
import { CheckCircleOutlined, ClockCircleOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import type { EstablishmentHealthPlanActivity } from '../../types/establishment-health-plan.types'
import { MONTH_LABELS } from '../../constants/establishment-health-plan.constants'

defineProps<{
  activities: EstablishmentHealthPlanActivity[]
  confirmingGuid: string | null
}>()

const emit = defineEmits<{
  confirm: [activity: EstablishmentHealthPlanActivity]
}>()
</script>

<template>
  <div class="ehpc-root">
    <table class="ehpc-table">
      <thead>
        <tr>
          <th class="ehpc-th ehpc-th--activity">Actividad</th>
          <th class="ehpc-th">Mes</th>
          <th class="ehpc-th">Fecha</th>
          <th class="ehpc-th">Estado</th>
          <th class="ehpc-th">Confirmado por</th>
          <th class="ehpc-th" />
        </tr>
      </thead>
      <tbody>
        <tr v-for="activity in activities" :key="activity.guid" class="ehpc-row">
          <td class="ehpc-td ehpc-td--activity">{{ activity.health_activity.name }}</td>
          <td class="ehpc-td">{{ MONTH_LABELS[activity.month - 1] }}</td>
          <td class="ehpc-td">{{ new Date(activity.due_date).toLocaleDateString('es-AR') }}</td>
          <td class="ehpc-td">
            <a-tag v-if="activity.status === 'confirmed'" color="green">
              <CheckCircleOutlined /> Confirmada
            </a-tag>
            <a-tag v-else color="orange">
              <ClockCircleOutlined /> Pendiente
            </a-tag>
          </td>
          <td class="ehpc-td">{{ activity.confirmed_by?.name ?? '—' }}</td>
          <td class="ehpc-td">
            <PermissionGuard permission="establishment-health-plans.confirm">
              <BaseButton
                v-if="activity.require_confirmation"
                size="small"
                :disabled="activity.status === 'confirmed'"
                :loading="confirmingGuid === activity.guid"
                @click="emit('confirm', activity)"
              >
                {{ activity.status === 'confirmed' ? 'Confirmada' : 'Confirmar' }}
              </BaseButton>
            </PermissionGuard>
          </td>
        </tr>

        <tr v-if="activities.length === 0">
          <td colspan="6" class="ehpc-empty">Este plan no tiene actividades.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.ehpc-root {
  overflow-x: auto;
  width: 100%;
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 10px;
}

.ehpc-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.ehpc-th {
  padding: 10px 12px;
  text-align: left;
  font-weight: 600;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--dt-muted, #6B8CAE);
  border-bottom: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  background: rgba(26, 229, 160, 0.04);
}

.ehpc-row:not(:last-child) {
  border-bottom: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
}

.ehpc-td {
  padding: 10px 12px;
  vertical-align: middle;
}

.ehpc-td--activity {
  font-weight: 500;
}

.ehpc-empty {
  text-align: center;
  padding: 24px;
  color: var(--dt-muted, #6B8CAE);
}
</style>
