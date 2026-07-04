export interface MetricData {
    clinics: number;
    doctors: number;
    activeAlerts: number;
    alertsToday: number;
    patients: number;
    resolvedAlerts: number;
}

export type AlertSeverity = 'critical' | 'high' | 'medium' | 'low';
export type AlertStatus   = 'active' | 'acknowledged' | 'resolved';
export type ActivityType  = 'alert' | 'user' | 'clinic' | 'system';

export interface AlertItem {
    id: string;
    clinic: string;
    patient: string;
    type: string;
    severity: AlertSeverity;
    status: AlertStatus;
    createdAt: string;
}

export interface ActivityItem {
    id: string;
    type: ActivityType;
    description: string;
    detail?: string;
    time: string;
}

export interface ChartData {
    labels: string[];
    alerts: number[];
    resolved: number[];
}
