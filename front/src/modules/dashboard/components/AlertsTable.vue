<script setup lang="ts">
import type { AlertItem, AlertSeverity, AlertStatus } from '@/modules/dashboard/types/dashboard.types';

defineProps<{ items: AlertItem[] }>();

const severityCfg: Record<AlertSeverity, { label: string; color: string; bg: string }> = {
    critical: { label: 'Crítica', color: '#FF5A6A', bg: 'rgba(255,90,106,0.12)'  },
    high:     { label: 'Alta',    color: '#FB923C', bg: 'rgba(251,146,60,0.12)'  },
    medium:   { label: 'Media',   color: '#FBBF24', bg: 'rgba(251,191,36,0.12)'  },
    low:      { label: 'Baja',    color: '#1AE5A0', bg: 'rgba(26,229,160,0.12)'  },
};

const statusCfg: Record<AlertStatus, { label: string; color: string }> = {
    active:       { label: 'Activa',   color: '#FF5A6A' },
    acknowledged: { label: 'Vista',    color: '#FBBF24' },
    resolved:     { label: 'Resuelta', color: '#1AE5A0' },
};

function fmtDate(iso: string) {
    return new Date(iso).toLocaleString('es-AR', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <div class="at-wrap">
        <table class="at-table">
            <thead>
                <tr>
                    <th>Clínica</th>
                    <th>Paciente</th>
                    <th>Tipo de alerta</th>
                    <th>Severidad</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in items" :key="row.id">
                    <td>{{ row.clinic }}</td>
                    <td>{{ row.patient }}</td>
                    <td>{{ row.type }}</td>
                    <td>
                        <span
                            class="at-badge"
                            :style="{ color: severityCfg[row.severity].color, background: severityCfg[row.severity].bg }"
                        >
                            {{ severityCfg[row.severity].label }}
                        </span>
                    </td>
                    <td>
                        <span class="at-status" :style="{ color: statusCfg[row.status].color }">
                            ● {{ statusCfg[row.status].label }}
                        </span>
                    </td>
                    <td class="at-mono">{{ fmtDate(row.createdAt) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.at-wrap { overflow-x: auto; }

.at-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}

.at-table th {
    text-align: left;
    padding: 8px 12px;
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--dt-muted, #6B8CAE);
    border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.1));
}

.at-table td {
    padding: 12px;
    border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.06));
    color: var(--dt-text, #C8E2EF);
    vertical-align: middle;
}

.at-table tr:last-child td { border-bottom: none; }
.at-table tr:hover td      { background: var(--dt-hover, rgba(26,229,160,0.025)); }

.at-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 6px;
}

.at-status {
    font-size: 12.5px;
    font-weight: 500;
}

.at-mono {
    color: var(--dt-muted, #6B8CAE) !important;
    font-size: 12px !important;
    font-family: 'JetBrains Mono', monospace;
}
</style>
