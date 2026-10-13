<script setup lang="ts">
import { STATUS_FILTER_LABEL } from '../../../constants/alert-copy'
import type { AlertStatusFilter, ProgramAlertsCounters } from '../../../types/program-alerts.types'

const props = defineProps<{
  counters: ProgramAlertsCounters
  status: AlertStatusFilter
}>()

const emit = defineEmits<{ 'update:status': [value: AlertStatusFilter] }>()

// "Cancelados" only appears when there is something to show (soft-cancelled history).
function options(): { value: AlertStatusFilter; count: number }[] {
  const base: { value: AlertStatusFilter; count: number }[] = [
    { value: 'all', count: props.counters.total },
    { value: 'scheduled', count: props.counters.scheduled },
    { value: 'sent', count: props.counters.sent },
    { value: 'problem', count: props.counters.problem },
  ]
  if (props.counters.cancelled > 0 || props.status === 'cancelled') {
    base.push({ value: 'cancelled', count: props.counters.cancelled })
  }
  return base
}
</script>

<template>
  <div class="pasb-root" role="tablist">
    <button
      v-for="opt in options()"
      :key="opt.value"
      type="button"
      role="tab"
      :aria-selected="status === opt.value"
      :data-testid="`alerts-status-${opt.value}`"
      class="pasb-item"
      :class="{ 'pasb-item--active': status === opt.value, 'pasb-item--problem': opt.value === 'problem' && opt.count > 0 }"
      @click="emit('update:status', opt.value)"
    >
      {{ STATUS_FILTER_LABEL[opt.value] }}
      <span class="pasb-count">{{ opt.count }}</span>
    </button>
  </div>
</template>

<style scoped>
.pasb-root {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.pasb-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border: 1px solid #d9d9d9;
  border-radius: 16px;
  background: #fff;
  cursor: pointer;
}

.pasb-item--active {
  border-color: #1677ff;
  color: #1677ff;
  background: #e6f4ff;
}

.pasb-item--problem .pasb-count {
  color: #cf1322;
  font-weight: 600;
}

.pasb-count {
  font-variant-numeric: tabular-nums;
}
</style>
