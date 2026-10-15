<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import TrendChart from './TrendChart.vue'
import { formatCount, formatDayLabel } from '../composables/useDashboardFormat'
import type { SentPerDay } from '../types/dashboard.types'

const props = defineProps<{
  items: SentPerDay[]
}>()

const { t } = useI18n()

const counts = computed(() => props.items.map(i => i.count))
const labels = computed(() => props.items.map(i => formatDayLabel(i.date)))
const total = computed(() => counts.value.reduce((sum, n) => sum + n, 0))
</script>

<template>
  <div class="sent-bar-chart" role="img" :aria-label="t('dashboard.sent.chartLabel')">
    <p v-if="total === 0" class="sent-bar-chart__empty">{{ t('dashboard.sent.empty') }}</p>
    <template v-else>
      <TrendChart :data="counts" :labels="labels" color="#1AE5A0" />
      <p class="sent-bar-chart__total">{{ t('dashboard.sent.total', { count: formatCount(total) }) }}</p>
    </template>
  </div>
</template>

<style scoped>
.sent-bar-chart__empty,
.sent-bar-chart__total {
  margin: 8px 0 0;
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}
</style>
