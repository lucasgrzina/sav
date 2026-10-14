<script setup lang="ts">
import {
  ALERTS_COPY,
  ATTEMPT_CHANNEL_LABEL,
  ATTEMPT_STATE_LABEL,
  FAILURE_COPY,
} from '../../../constants/alert-copy'
import { useAlertLinePresentation } from '../../../composables/useAlertLinePresentation'
import type { ProgramAlertLine } from '../../../types/program-alerts.types'

defineProps<{ line: ProgramAlertLine }>()

const { formatDeliveredAt } = useAlertLinePresentation()
</script>

<template>
  <div class="paad-root">
    <p class="paad-title">{{ ALERTS_COPY.attempts }}</p>
    <ul class="paad-list">
      <li v-for="attempt in line.attempts" :key="attempt.recipient_guid" class="paad-item">
        <span class="paad-channel">{{ ATTEMPT_CHANNEL_LABEL[attempt.channel] ?? attempt.channel }}</span>
        <span>{{ ATTEMPT_STATE_LABEL[attempt.state] ?? attempt.state }}</span>
        <span v-if="attempt.destination_masked" class="paad-muted">{{ attempt.destination_masked }}</span>
        <span v-if="attempt.delivered_at" class="paad-muted">
          Entregado {{ formatDeliveredAt(attempt.delivered_at) }}
        </span>
        <span v-if="attempt.failure_code" class="paad-failure">
          {{ FAILURE_COPY[attempt.failure_code].text }}
        </span>
      </li>
    </ul>
    <p v-if="line.message" class="paad-message">{{ line.message }}</p>
  </div>
</template>

<style scoped>
.paad-title {
  margin: 0 0 4px;
  font-weight: 600;
}

.paad-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.paad-item {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 2px 0;
}

.paad-channel {
  font-weight: 500;
}

.paad-muted {
  color: rgba(0, 0, 0, 0.45);
}

.paad-failure {
  color: #cf1322;
}

.paad-message {
  margin: 8px 0 0;
  color: rgba(0, 0, 0, 0.65);
}
</style>
