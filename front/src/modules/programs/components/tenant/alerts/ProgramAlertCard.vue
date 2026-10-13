<script setup lang="ts">
import { computed } from 'vue'
import ProgramAlertAttemptsDetail from './ProgramAlertAttemptsDetail.vue'
import ProgramAlertStatusTag from './ProgramAlertStatusTag.vue'
import { useAlertLinePresentation } from '../../../composables/useAlertLinePresentation'
import type { ProgramAlertLine } from '../../../types/program-alerts.types'

const props = defineProps<{ line: ProgramAlertLine; expanded: boolean }>()
const emit = defineEmits<{ toggle: [key: string] }>()

const { statusNote, typeLabel, taskLabel, formatDeliveredAt } = useAlertLinePresentation()
const note = computed(() => statusNote(props.line))
const deliveredAt = computed(() => formatDeliveredAt(props.line.delivered_at))
</script>

<template>
  <div class="pac-root" data-testid="alert-card">
    <div class="pac-head">
      <span class="pac-date">{{ line.scheduled_at_local.label }}</span>
      <ProgramAlertStatusTag :state="line.state" />
    </div>
    <p class="pac-recipient">{{ line.recipient.name }}</p>
    <p class="pac-task">{{ typeLabel(line) }} · {{ taskLabel(line) }}</p>
    <p v-if="line.group?.label" class="pac-muted">{{ line.group.label }}</p>
    <p v-if="line.destination_masked" class="pac-muted">{{ line.destination_masked }}</p>
    <p v-if="deliveredAt && (line.state === 'read' || line.state === 'delivered')" class="pac-muted">
      Entregado {{ deliveredAt }}
    </p>
    <p v-if="note" class="pac-note" :class="{ 'pac-note--problem': line.bucket === 'problem' }">{{ note }}</p>
    <button
      v-if="line.attempts.length"
      type="button"
      class="pac-toggle"
      @click="emit('toggle', line.key)"
    >
      {{ expanded ? 'Ocultar detalle' : 'Ver detalle' }}
    </button>
    <ProgramAlertAttemptsDetail v-if="expanded" :line="line" />
  </div>
</template>

<style scoped>
.pac-root {
  padding: 12px;
  border: 1px solid #f0f0f0;
  border-radius: 8px;
  background: #fff;
  overflow-wrap: anywhere;
}

.pac-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.pac-date {
  font-weight: 600;
}

.pac-recipient,
.pac-task,
.pac-muted,
.pac-note {
  margin: 4px 0 0;
}

.pac-muted {
  color: rgba(0, 0, 0, 0.45);
}

.pac-note--problem {
  color: #cf1322;
}

.pac-toggle {
  margin-top: 8px;
  padding: 0;
  border: 0;
  background: none;
  color: #1677ff;
  cursor: pointer;
}
</style>
