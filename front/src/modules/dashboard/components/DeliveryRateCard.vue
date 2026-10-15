<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatPercent } from '../composables/useDashboardFormat'
import type { AdminDashboardData } from '../types/dashboard.types'

defineProps<{
  delivery: AdminDashboardData['delivery_30d']
}>()

const { t, te } = useI18n()

function channelLabel(channel: string): string {
  const key = `dashboard.channels.${channel}`
  return te(key) ? t(key) : channel
}
</script>

<template>
  <div class="delivery-rate">
    <div class="delivery-rate__overall" data-testid="delivery-overall">
      {{ formatPercent(delivery.rate) }}
    </div>

    <p v-if="delivery.by_channel.length === 0" class="delivery-rate__empty">
      {{ t('dashboard.delivery.empty') }}
    </p>

    <ul v-else class="delivery-rate__list">
      <li v-for="item in delivery.by_channel" :key="item.channel" class="delivery-rate__item">
        <div class="delivery-rate__row">
          <span>{{ channelLabel(item.channel) }}</span>
          <strong data-testid="channel-rate">{{ formatPercent(item.rate) }}</strong>
        </div>
        <a-progress
          :percent="item.rate ?? 0"
          :show-info="false"
          size="small"
          :aria-label="t('dashboard.delivery.progressLabel', { channel: channelLabel(item.channel), rate: formatPercent(item.rate) })"
        />
        <span class="delivery-rate__detail">
          {{ t('dashboard.delivery.detail', { ok: item.ok, failed: item.failed }) }}
        </span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.delivery-rate__overall {
  font-family: 'Syne', sans-serif;
  font-size: 38px;
  font-weight: 700;
  line-height: 1;
  color: var(--dt-val-accent, #1AE5A0);
  margin-bottom: 14px;
}

.delivery-rate__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.delivery-rate__row {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
}

.delivery-rate__detail,
.delivery-rate__empty {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}
</style>
