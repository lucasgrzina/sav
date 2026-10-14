<script setup lang="ts">
import { computed } from 'vue'
import { SEVERITY_ORDER, WARNING_COPY } from '../../../constants/alert-copy'
import type { ProgramWarning, WarningSeverity } from '../../../types/program-alerts.types'

const props = defineProps<{ warnings: ProgramWarning[] }>()

const SEVERITY_TAG: Record<WarningSeverity, { color: string; label: string }> = {
  critical: { color: 'red', label: 'No se envía' },
  warning: { color: 'orange', label: 'Atención' },
  info: { color: 'blue', label: 'Info' },
}

// Most severe first; stable inside the same severity.
const sorted = computed(() =>
  props.warnings
    .map((warning, index) => ({ warning, index }))
    .sort((a, b) => SEVERITY_ORDER[a.warning.severity] - SEVERITY_ORDER[b.warning.severity] || a.index - b.index)
    .map(({ warning }) => warning),
)
</script>

<template>
  <ul class="pwl-root" data-testid="warning-list">
    <li v-for="(w, i) in sorted" :key="`${w.code}-${i}`" class="pwl-item">
      <a-tag :color="SEVERITY_TAG[w.severity].color">{{ SEVERITY_TAG[w.severity].label }}</a-tag>
      <strong>{{ WARNING_COPY[w.code]?.title ?? w.code }}</strong>
      <p class="pwl-detail">{{ WARNING_COPY[w.code]?.detail(w.context) ?? '' }}</p>
    </li>
  </ul>
</template>

<style scoped>
.pwl-root {
  margin: 0;
  padding: 0;
  list-style: none;
}

.pwl-item {
  padding: 8px 0;
  border-bottom: 1px solid #f0f0f0;
}

.pwl-detail {
  margin: 4px 0 0;
}
</style>
