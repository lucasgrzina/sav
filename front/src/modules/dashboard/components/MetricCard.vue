<script setup lang="ts">
import { computed } from 'vue';
import { ArrowUpOutlined, ArrowDownOutlined } from '@ant-design/icons-vue';

const props = withDefaults(defineProps<{
    title: string;
    value: string | number;
    description?: string;
    trend?: 'up' | 'down' | 'neutral';
    trendValue?: string;
    color?: 'accent' | 'warning' | 'danger' | 'info';
    loading?: boolean;
}>(), {
    color: 'accent',
    loading: false,
});

const colorMap = {
    accent:  { border: 'rgba(26,229,160,0.2)',  value: 'var(--dt-val-accent, #1AE5A0)'  },
    info:    { border: 'rgba(56,189,248,0.2)',   value: 'var(--dt-val-info, #38BDF8)'    },
    warning: { border: 'rgba(251,191,36,0.2)',   value: 'var(--dt-val-warning, #FBBF24)' },
    danger:  { border: 'rgba(255,90,106,0.2)',   value: 'var(--dt-val-danger, #FF5A6A)'  },
};

const c = computed(() => colorMap[props.color]);

const trendColor = computed(() => {
    if (props.trend === 'down') return '#FF5A6A';
    if (props.trend === 'neutral') return 'var(--dt-muted, #6B8CAE)';
    return '#1AE5A0';
});
</script>

<template>
    <div class="metric-card" :style="{ borderColor: c.border }">
        <template v-if="loading">
            <div class="mc-skel-header">
                <div class="mc-skel mc-skel-sm" />
                <div class="mc-skel mc-skel-icon" />
            </div>
            <div class="mc-skel mc-skel-lg" style="margin-top: 14px" />
            <div class="mc-skel mc-skel-sm" style="margin-top: 12px; width: 55%" />
        </template>
        <template v-else>
            <div class="metric-card-header">
                <span class="metric-card-title">{{ title }}</span>
                <slot name="icon" />
            </div>
            <div class="metric-card-value" :style="{ color: c.value }">
                {{ value }}
            </div>
            <div v-if="trendValue || description" class="metric-card-footer">
                <span v-if="trendValue" class="metric-card-trend" :style="{ color: trendColor }">
                    <ArrowUpOutlined v-if="trend === 'up'" />
                    <ArrowDownOutlined v-if="trend === 'down'" />
                    {{ trendValue }}
                </span>
                <span v-if="description" class="metric-card-desc">{{ description }}</span>
            </div>
        </template>
    </div>
</template>

<style scoped>
.metric-card {
    background: var(--dt-card, #0E2038);
    border: 1px solid;
    border-radius: 16px;
    padding: 20px 22px;
    transition: box-shadow 0.25s, transform 0.2s, background 0.25s;
    cursor: default;
    box-shadow: var(--dt-card-shadow, none);
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}

.metric-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}

.metric-card-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: var(--dt-muted, #6B8CAE);
}

.metric-card-value {
    font-family: 'Syne', sans-serif;
    font-size: 38px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: -0.03em;
}

.metric-card-footer {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.metric-card-trend {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    font-weight: 600;
}

.metric-card-desc {
    font-size: 12px;
    color: var(--dt-muted, #6B8CAE);
}

/* Skeleton */
.mc-skel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.mc-skel {
    background: rgba(255, 255, 255, 0.07);
    border-radius: 4px;
    animation: mc-pulse 1.6s ease infinite;
}

.mc-skel-sm   { height: 11px; width: 80px; }
.mc-skel-icon { height: 32px; width: 32px; border-radius: 8px; }
.mc-skel-lg   { height: 38px; width: 110px; }

@keyframes mc-pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.35; }
}
</style>
