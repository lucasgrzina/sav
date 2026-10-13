<script setup lang="ts">
import ProgramAlertAttemptsDetail from './ProgramAlertAttemptsDetail.vue'
import ProgramAlertStatusTag from './ProgramAlertStatusTag.vue'
import { useAlertLinePresentation } from '../../../composables/useAlertLinePresentation'
import type { ProgramAlertLine } from '../../../types/program-alerts.types'

defineProps<{ lines: ProgramAlertLine[]; loading: boolean; expandedKeys: string[] }>()
const emit = defineEmits<{ toggle: [key: string] }>()

const { statusNote, typeLabel, taskLabel, formatDeliveredAt } = useAlertLinePresentation()

const columns = [
  { title: 'Fecha', key: 'date', width: 170 },
  { title: 'Persona', key: 'recipient', width: 200 },
  { title: 'Aviso', key: 'alert' },
  { title: 'Estado', key: 'state', width: 320 },
]
</script>

<template>
  <a-table
    :columns="columns"
    :data-source="lines"
    :loading="loading"
    :pagination="false"
    row-key="key"
    size="middle"
    :expanded-row-keys="expandedKeys"
    :row-expandable="(record: ProgramAlertLine) => record.attempts.length > 0"
    data-testid="alerts-table"
    @expand="(_expanded: boolean, record: ProgramAlertLine) => emit('toggle', record.key)"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">
        {{ (record as ProgramAlertLine).scheduled_at_local.label }}
      </template>
      <template v-else-if="column.key === 'recipient'">
        <div>{{ (record as ProgramAlertLine).recipient.name }}</div>
        <div v-if="(record as ProgramAlertLine).destination_masked" class="pat-muted">
          {{ (record as ProgramAlertLine).destination_masked }}
        </div>
      </template>
      <template v-else-if="column.key === 'alert'">
        <div>{{ typeLabel(record as ProgramAlertLine) }} · {{ taskLabel(record as ProgramAlertLine) }}</div>
        <div v-if="(record as ProgramAlertLine).group?.label" class="pat-muted">
          {{ (record as ProgramAlertLine).group?.label }}
        </div>
      </template>
      <template v-else-if="column.key === 'state'">
        <ProgramAlertStatusTag :state="(record as ProgramAlertLine).state" />
        <span
          v-if="['read', 'delivered'].includes((record as ProgramAlertLine).state) && formatDeliveredAt((record as ProgramAlertLine).delivered_at)"
          class="pat-muted"
        >
          {{ formatDeliveredAt((record as ProgramAlertLine).delivered_at) }}
        </span>
        <div
          v-if="statusNote(record as ProgramAlertLine)"
          class="pat-note"
          :class="{ 'pat-note--problem': (record as ProgramAlertLine).bucket === 'problem' }"
        >
          {{ statusNote(record as ProgramAlertLine) }}
        </div>
      </template>
    </template>
    <template #expandedRowRender="{ record }">
      <ProgramAlertAttemptsDetail :line="record as ProgramAlertLine" />
    </template>
  </a-table>
</template>

<style scoped>
.pat-muted {
  color: rgba(0, 0, 0, 0.45);
}

.pat-note {
  margin-top: 4px;
}

.pat-note--problem {
  color: #cf1322;
}
</style>
