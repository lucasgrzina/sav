// import { http } from '@/core/api/http'
//
// Cuando existan endpoints reales, reemplazar cada función con:
//   export async function getMetricsApi(): Promise<MetricData> {
//     const response = await http.get<MetricData>('/dashboard/metrics')
//     return response.data
//   }
//   export async function getAlertsApi(): Promise<AlertItem[]> {
//     const response = await http.get<AlertItem[]>('/dashboard/alerts')
//     return response.data
//   }
//   export async function getActivityApi(): Promise<ActivityItem[]> {
//     const response = await http.get<ActivityItem[]>('/dashboard/activity')
//     return response.data
//   }
//   export async function getChartApi(): Promise<ChartData> {
//     const response = await http.get<ChartData>('/dashboard/chart')
//     return response.data
//   }

import type { MetricData, AlertItem, ActivityItem, ChartData } from '@/modules/dashboard/types/dashboard.types'

// ── Mock data (extraída del store legacy) ─────────────────────────────────────

const MOCK_METRICS: MetricData = {
    clinics:        48,
    doctors:        124,
    activeAlerts:   23,
    alertsToday:    247,
    patients:       1203,
    resolvedAlerts: 224,
}

const MOCK_ALERTS: AlertItem[] = [
    { id: '1', clinic: 'Clínica Vet Norte',   patient: 'Max (Labrador)',    type: 'Temperatura crítica',         severity: 'critical', status: 'active',       createdAt: '2026-05-13T10:32:00Z' },
    { id: '2', clinic: 'Vetcare Centro',       patient: 'Luna (Gato Persa)', type: 'Frecuencia cardíaca anormal', severity: 'high',     status: 'acknowledged', createdAt: '2026-05-13T09:15:00Z' },
    { id: '3', clinic: 'Clínica Sur Mascotas', patient: 'Rocky (Bulldog)',   type: 'Saturación de oxígeno baja',  severity: 'high',     status: 'active',       createdAt: '2026-05-13T08:45:00Z' },
    { id: '4', clinic: 'Vet Palermo',          patient: 'Coco (Caniche)',    type: 'Presión arterial elevada',    severity: 'medium',   status: 'resolved',     createdAt: '2026-05-13T07:30:00Z' },
    { id: '5', clinic: 'Clínica Vet Norte',    patient: 'Nala (Golden)',     type: 'Monitoreo post-operatorio',   severity: 'low',      status: 'resolved',     createdAt: '2026-05-12T23:10:00Z' },
]

const MOCK_ACTIVITY: ActivityItem[] = [
    { id: '1', type: 'alert',  description: 'Alerta crítica generada',        detail: 'Temperatura crítica — Max (Vet Norte)',        time: 'Hace 2 min'  },
    { id: '2', type: 'clinic', description: 'Nueva clínica registrada',       detail: 'Vetcare Belgrano',                             time: 'Hace 18 min' },
    { id: '3', type: 'user',   description: 'Nuevo veterinario dado de alta', detail: 'Dr. Martín Vidal',                             time: 'Hace 34 min' },
    { id: '4', type: 'alert',  description: 'Alerta resuelta',                detail: 'Presión arterial — Coco (Vet Palermo)',        time: 'Hace 1 h'    },
    { id: '5', type: 'system', description: 'Backup del sistema completado',  detail: 'Snapshot: 2026-05-13 07:00',                   time: 'Hace 3 h'    },
    { id: '6', type: 'clinic', description: 'Clínica actualizada',            detail: 'Clínica Sur Mascotas — nuevo médico asignado', time: 'Hace 5 h'    },
]

const MOCK_CHART: ChartData = {
    labels:   ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Hoy'],
    alerts:   [189, 223, 156, 298, 211, 174, 247],
    resolved: [140, 200, 130, 260, 190, 160, 224],
}

// ── API functions (mock con fallback, listos para reemplazar con http calls) ──

export async function getMetricsApi(): Promise<MetricData> {
    // TODO: reemplazar con http.get<MetricData>('/dashboard/metrics')
    return Promise.resolve(MOCK_METRICS)
}

export async function getAlertsApi(): Promise<AlertItem[]> {
    // TODO: reemplazar con http.get<AlertItem[]>('/dashboard/alerts')
    return Promise.resolve(MOCK_ALERTS)
}

export async function getActivityApi(): Promise<ActivityItem[]> {
    // TODO: reemplazar con http.get<ActivityItem[]>('/dashboard/activity')
    return Promise.resolve(MOCK_ACTIVITY)
}

export async function getChartApi(): Promise<ChartData> {
    // TODO: reemplazar con http.get<ChartData>('/dashboard/chart')
    return Promise.resolve(MOCK_CHART)
}
