<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Dayjs } from 'dayjs'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseDataTable from '@/components/tables/BaseDataTable.vue'
import { getRoleLabel } from '@/core/utils/roles'
import { useProtocolSimulation } from '../composables/useProtocolSimulation'
import type { SimulatedAlert, SimulatedTask } from '../types/protocol.types'

const props = defineProps<{ protocolGuid: string; protocolName: string }>()

const open = defineModel<boolean>('open', { default: false })

const baseDate = ref<Dayjs | null>(null)
const baseDateParam = computed(() => (baseDate.value ? baseDate.value.format('YYYY-MM-DD') : undefined))

const { data, isLoading, isError } = useProtocolSimulation(
  computed(() => props.protocolGuid),
  baseDateParam,
)

function taskRowClassName(record: SimulatedTask, index: number): string {
  const classes = [taskDateSpans.value[index] > 0 && index > 0 ? 'psd-row--group-start' : '']
  if (record.important) classes.push('psd-row--important')
  return classes.filter(Boolean).join(' ')
}

function alertRowClassName(record: SimulatedAlert, index: number): string {
  const classes = [alertDateSpans.value[index] > 0 && index > 0 ? 'psd-row--group-start' : '']
  if (record.require_confirmation) classes.push('psd-row--critical')
  return classes.filter(Boolean).join(' ')
}

function formatDate(value: string): string {
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

/** Fusiona filas consecutivas con la misma fecha (los datos ya vienen ordenados cronológicamente del backend). */
function computeDateRowSpans(dates: string[]): number[] {
  const spans = new Array(dates.length).fill(1)
  let start = 0
  for (let i = 1; i <= dates.length; i++) {
    if (i === dates.length || dates[i] !== dates[start]) {
      spans[start] = i - start
      for (let k = start + 1; k < i; k++) spans[k] = 0
      start = i
    }
  }
  return spans
}

const taskDateSpans = computed(() => computeDateRowSpans((data.value?.tasks ?? []).map((t) => t.computed_date)))
const alertDateSpans = computed(() => computeDateRowSpans((data.value?.alerts ?? []).map((a) => a.computed_date)))

const taskColumns = computed(() => [
  {
    title: 'Fecha',
    key: 'computed_date',
    customCell: (_record: SimulatedTask, index: number) => ({ rowSpan: taskDateSpans.value[index] }),
  },
  { title: 'Hora', key: 'computed_time', dataIndex: 'computed_time' },
  { title: 'Tarea', key: 'description', dataIndex: 'description' },
])

const alertColumns = computed(() => [
  {
    title: 'Fecha',
    key: 'computed_date',
    customCell: (_record: SimulatedAlert, index: number) => ({ rowSpan: alertDateSpans.value[index] }),
  },
  { title: 'Hora', key: 'computed_time', dataIndex: 'computed_time' },
  { title: 'Tarea asociada', key: 'task_description', dataIndex: 'task_description' },
  { title: 'Mensaje', key: 'message', dataIndex: 'message' },
  { title: 'Destinatarios', key: 'roles' },
])
</script>

<template>
  <BaseDrawer
    v-model="open"
    :title="`Simular protocolo — ${protocolName}`"
    :width="720"
  >
    <div class="psd-content">
      <a-date-picker
        v-model:value="baseDate"
        format="DD/MM/YYYY"
        placeholder="Elegí una fecha base"
        style="width: 100%"
      />

      <a-empty v-if="!baseDate" description="Elegí una fecha base para calcular el cronograma." />

      <a-alert
        v-else-if="isError"
        type="error"
        message="No se pudo calcular el cronograma. Intentá nuevamente."
        show-icon
      />

      <template v-else>
        <h3 class="psd-section-title">Tareas</h3>
        <BaseDataTable
          :columns="taskColumns"
          :data-source="data?.tasks ?? []"
          :loading="isLoading"
          row-key="guid"
          :pagination="false"
          :row-class-name="taskRowClassName"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'computed_date'">
              {{ formatDate((record as SimulatedTask).computed_date) }}
            </template>
          </template>
        </BaseDataTable>

        <h3 class="psd-section-title">Alertas</h3>
        <BaseDataTable
          :columns="alertColumns"
          :data-source="data?.alerts ?? []"
          :loading="isLoading"
          row-key="guid"
          :pagination="false"
          :row-class-name="alertRowClassName"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'roles'">
              {{ (record as SimulatedAlert).roles.map(getRoleLabel).join(', ') }}
            </template>
            <template v-else-if="column.key === 'computed_date'">
              {{ formatDate((record as SimulatedAlert).computed_date) }}
            </template>
          </template>
        </BaseDataTable>
      </template>
    </div>
  </BaseDrawer>
</template>

<style scoped>
.psd-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.psd-section-title {
  margin: 0;
  font-weight: 600;
}

:deep(.psd-row--group-start td) {
  border-top: 2px solid var(--dt-border, rgba(26, 229, 160, 0.25));
}

:deep(.psd-row--important td:nth-child(3)) {
  background: rgba(250, 173, 20, 0.08);
  border-left: 3px solid #faad14;
}

:deep(.psd-row--critical td:nth-child(4)) {
  background: rgba(245, 34, 45, 0.08);
  border-left: 3px solid #f5222d;
}
</style>
