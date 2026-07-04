<script setup lang="ts">
import { computed } from 'vue';
import { useAuth } from '@/composables/useAuth';
import {
    MedicineBoxOutlined,
    TeamOutlined,
    BellOutlined,
    HeartOutlined,
    CheckCircleOutlined,
    HomeOutlined,
} from '@ant-design/icons-vue';

import AsyncContent from '@/components/shared/AsyncContent.vue';
import MetricCard   from '../components/MetricCard.vue';
import TrendChart   from '../components/TrendChart.vue';
import AlertsTable  from '../components/AlertsTable.vue';
import ActivityFeed from '../components/ActivityFeed.vue';

import { useMetrics }  from '../composables/useMetrics';
import { useAlerts }   from '../composables/useAlerts';
import { useActivity } from '../composables/useActivity';
import { useChart }    from '../composables/useChart';

const { user } = useAuth();

const { data: metrics,  isLoading: metricsLoading,  error: metricsError,  refetch: refetchMetrics  } = useMetrics();
const { data: alerts,   isLoading: alertsLoading,   error: alertsError,   refetch: refetchAlerts   } = useAlerts();
const { data: activity, isLoading: activityLoading, error: activityError, refetch: refetchActivity } = useActivity();
const { data: chart,    isLoading: chartLoading,    error: chartError,    refetch: refetchChart    } = useChart();

const isLoading = computed(() =>
    metricsLoading.value || alertsLoading.value || activityLoading.value || chartLoading.value
);

// Cualquier error de las queries se propaga al AsyncContent
const firstError = computed(() =>
    metricsError.value ?? alertsError.value ?? activityError.value ?? chartError.value ?? null
);

function refetchAll() {
    void refetchMetrics();
    void refetchAlerts();
    void refetchActivity();
    void refetchChart();
}

const todayLabel = computed(() =>
    new Date().toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' })
);

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Buenos días';
    if (hour < 19) return 'Buenas tardes';
    return 'Buenas noches';
});
</script>

<template>
    <div class="dash-view">
        <!-- Welcome -->
        <div class="dash-welcome">
            <div>
                <h2 class="dash-welcome-title">{{ greeting }}, {{ user?.first_name }}</h2>
                <p class="dash-welcome-sub">{{ todayLabel }}</p>
            </div>
            <div class="dash-live-badge">
                <span class="dash-live-dot" />
                Sistema en línea
            </div>
        </div>

        <AsyncContent :loading="isLoading" :error="firstError" @retry="refetchAll">
            <!-- Metrics grid -->
            <div class="dash-metrics-grid">
                <MetricCard
                    title="Clínicas activas"
                    :value="metrics?.clinics ?? '—'"
                    color="accent"
                    trend="up"
                    trend-value="+3 este mes"
                >
                    <template #icon>
                        <div class="metric-icon metric-icon--accent"><HomeOutlined /></div>
                    </template>
                </MetricCard>

                <MetricCard
                    title="Veterinarios"
                    :value="metrics?.doctors ?? '—'"
                    color="info"
                    trend="up"
                    trend-value="+8 este mes"
                >
                    <template #icon>
                        <div class="metric-icon metric-icon--info"><MedicineBoxOutlined /></div>
                    </template>
                </MetricCard>

                <MetricCard
                    title="Usuarios del sistema"
                    :value="'—'"
                    color="info"
                >
                    <template #icon>
                        <div class="metric-icon metric-icon--info"><TeamOutlined /></div>
                    </template>
                </MetricCard>

                <MetricCard
                    title="Pacientes monitoreados"
                    :value="metrics?.patients != null ? metrics.patients.toLocaleString('es-AR') : '—'"
                    color="accent"
                    trend="up"
                    trend-value="+24 hoy"
                >
                    <template #icon>
                        <div class="metric-icon metric-icon--accent"><HeartOutlined /></div>
                    </template>
                </MetricCard>

                <MetricCard
                    title="Alertas activas"
                    :value="metrics?.activeAlerts ?? '—'"
                    color="danger"
                    trend="down"
                    trend-value="-5 vs ayer"
                >
                    <template #icon>
                        <div class="metric-icon metric-icon--danger"><BellOutlined /></div>
                    </template>
                </MetricCard>

                <MetricCard
                    title="Resueltas hoy"
                    :value="metrics?.resolvedAlerts ?? '—'"
                    color="accent"
                    trend="up"
                    trend-value="91% tasa"
                >
                    <template #icon>
                        <div class="metric-icon metric-icon--accent"><CheckCircleOutlined /></div>
                    </template>
                </MetricCard>
            </div>

            <!-- Charts + Activity -->
            <div class="dash-charts-row">
                <!-- Alerts trend chart -->
                <div class="dash-section-card">
                    <div class="dash-section-header">
                        <div>
                            <h3 class="dash-section-title">Alertas por día</h3>
                            <p class="dash-section-sub">Últimos 7 días</p>
                        </div>
                        <div class="dash-today-badge">
                            <strong>{{ metrics?.alertsToday ?? '—' }}</strong> hoy
                        </div>
                    </div>

                    <TrendChart
                        v-if="chart"
                        :data="chart.alerts"
                        :labels="chart.labels"
                        color="#1AE5A0"
                    />
                    <TrendChart
                        v-if="chart"
                        :data="chart.resolved"
                        :labels="chart.labels"
                        color="#38BDF8"
                        style="margin-top: 10px"
                    />

                    <div class="dash-chart-legend">
                        <span class="dash-legend-dot" style="background: #1AE5A0" />
                        Generadas
                        <span class="dash-legend-dot" style="background: #38BDF8; margin-left: 14px" />
                        Resueltas
                    </div>
                </div>

                <!-- Activity feed -->
                <div class="dash-section-card">
                    <div class="dash-section-header">
                        <div>
                            <h3 class="dash-section-title">Actividad reciente</h3>
                            <p class="dash-section-sub">Últimas acciones del sistema</p>
                        </div>
                    </div>
                    <ActivityFeed v-if="activity" :items="activity" />
                </div>
            </div>

            <!-- Recent alerts table -->
            <div class="dash-section-card">
                <div class="dash-section-header">
                    <div>
                        <h3 class="dash-section-title">Últimas alertas</h3>
                        <p class="dash-section-sub">Alertas críticas y activas registradas</p>
                    </div>
                </div>
                <AlertsTable v-if="alerts" :items="alerts" />
            </div>
        </AsyncContent>
    </div>
</template>
