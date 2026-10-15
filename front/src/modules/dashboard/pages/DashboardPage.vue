<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuth } from '@/composables/useAuth'
import { usePermission } from '@/core/composables/usePermissions'
import {
  HomeOutlined,
  ClockCircleOutlined,
  IdcardOutlined,
  EnvironmentOutlined,
  HeartOutlined,
  ScheduleOutlined,
} from '@ant-design/icons-vue'

import AsyncContent from '@/components/shared/AsyncContent.vue'
import MetricCard from '../components/MetricCard.vue'
import SentBarChart from '../components/SentBarChart.vue'
import DeliveryRateCard from '../components/DeliveryRateCard.vue'
import { useAdminDashboard } from '../composables/useAdminDashboard'
import { formatCount } from '../composables/useDashboardFormat'

const { t } = useI18n()
const { user } = useAuth()
const { can } = usePermission()

const canViewStats = computed(() => can('dashboard.admin.read'))
const { data, isLoading, isFetching, error, refetch } = useAdminDashboard(canViewStats)

function retry() {
  void refetch()
}

const todayLabel = computed(() =>
  new Date().toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }),
)

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return t('dashboard.greeting.morning')
  if (hour < 19) return t('dashboard.greeting.afternoon')
  return t('dashboard.greeting.evening')
})

const generatedAtLabel = computed(() => {
  if (!data.value) return ''
  const time = new Date(data.value.generated_at).toLocaleTimeString('es-AR', {
    hour: '2-digit',
    minute: '2-digit',
  })
  return t('dashboard.generatedAt', { time })
})
</script>

<template>
  <div class="dash-view">
    <div class="dash-welcome">
      <div>
        <h2 class="dash-welcome-title">{{ greeting }}, {{ user?.first_name }}</h2>
        <p class="dash-welcome-sub">{{ todayLabel }}</p>
      </div>
      <div v-if="canViewStats" class="dash-welcome-actions">
        <span v-if="data" class="dash-welcome-sub" data-testid="generated-at">{{ generatedAtLabel }}</span>
        <a-button size="small" data-testid="refresh" :loading="isFetching" @click="retry">{{ t('dashboard.refresh') }}</a-button>
      </div>
    </div>

    <p v-if="!canViewStats" class="dash-welcome-sub" data-testid="no-access">
      {{ t('dashboard.noAccess') }}
    </p>

    <AsyncContent
      v-else
      :loading="isLoading"
      :error="error"
      :error-message="t('dashboard.errorMessage')"
      @retry="retry"
    >
      <template v-if="data">
        <div class="dash-metrics-grid">
          <MetricCard
            :title="t('dashboard.metrics.activeVets')"
            :value="formatCount(data.vets.active)"
            :description="t('dashboard.metrics.activeVetsDesc', { suspended: data.vets.suspended, total: data.vets.total })"
            color="accent"
          >
            <template #icon><div class="metric-icon metric-icon--accent"><HomeOutlined /></div></template>
          </MetricCard>

          <MetricCard
            :title="t('dashboard.metrics.pendingVets')"
            :value="formatCount(data.vets.pending)"
            :description="t('dashboard.metrics.pendingVetsDesc')"
            :color="data.vets.pending > 0 ? 'warning' : 'info'"
            data-testid="pending-vets"
          >
            <template #icon><div class="metric-icon metric-icon--info"><ClockCircleOutlined /></div></template>
          </MetricCard>

          <MetricCard :title="t('dashboard.metrics.clients')" :value="formatCount(data.totals.clients)" color="info">
            <template #icon><div class="metric-icon metric-icon--info"><IdcardOutlined /></div></template>
          </MetricCard>

          <MetricCard
            :title="t('dashboard.metrics.establishments')"
            :value="formatCount(data.totals.establishments)"
            color="info"
          >
            <template #icon><div class="metric-icon metric-icon--info"><EnvironmentOutlined /></div></template>
          </MetricCard>

          <MetricCard :title="t('dashboard.metrics.animals')" :value="formatCount(data.totals.animals)" color="accent">
            <template #icon><div class="metric-icon metric-icon--accent"><HeartOutlined /></div></template>
          </MetricCard>

          <MetricCard
            :title="t('dashboard.metrics.activePrograms')"
            :value="formatCount(data.totals.active_programs)"
            :description="t('dashboard.metrics.activeProgramsDesc')"
            color="accent"
          >
            <template #icon><div class="metric-icon metric-icon--accent"><ScheduleOutlined /></div></template>
          </MetricCard>
        </div>

        <div class="dash-charts-row">
          <div class="dash-section-card">
            <div class="dash-section-header">
              <div>
                <h3 class="dash-section-title">{{ t('dashboard.sent.title') }}</h3>
                <p class="dash-section-sub">{{ t('dashboard.sent.subtitle') }}</p>
              </div>
            </div>
            <SentBarChart :items="data.sent_last_7d" />
          </div>

          <div class="dash-section-card">
            <div class="dash-section-header">
              <div>
                <h3 class="dash-section-title">{{ t('dashboard.delivery.title') }}</h3>
                <p class="dash-section-sub">{{ t('dashboard.delivery.subtitle') }}</p>
              </div>
            </div>
            <DeliveryRateCard :delivery="data.delivery_30d" />
          </div>
        </div>
      </template>
    </AsyncContent>
  </div>
</template>
