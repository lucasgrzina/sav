<script setup lang="ts">
import type { ActivityItem, ActivityType } from '@/modules/dashboard/types/dashboard.types';
import { BellOutlined, UserOutlined, HomeOutlined, SettingOutlined } from '@ant-design/icons-vue';

defineProps<{ items: ActivityItem[] }>();

const iconCfg: Record<ActivityType, { icon: object; color: string; bg: string }> = {
    alert:  { icon: BellOutlined,    color: '#FF5A6A', bg: 'rgba(255,90,106,0.1)'  },
    user:   { icon: UserOutlined,    color: '#1AE5A0', bg: 'rgba(26,229,160,0.1)'  },
    clinic: { icon: HomeOutlined,    color: '#38BDF8', bg: 'rgba(56,189,248,0.1)'  },
    system: { icon: SettingOutlined, color: '#FBBF24', bg: 'rgba(251,191,36,0.1)'  },
};
</script>

<template>
    <div class="af-list">
        <div v-for="item in items" :key="item.id" class="af-item">
            <div class="af-icon" :style="{ background: iconCfg[item.type].bg }">
                <component
                    :is="iconCfg[item.type].icon"
                    :style="{ color: iconCfg[item.type].color, fontSize: '14px' }"
                />
            </div>
            <div class="af-body">
                <div class="af-desc">{{ item.description }}</div>
                <div v-if="item.detail" class="af-detail">{{ item.detail }}</div>
            </div>
            <div class="af-time">{{ item.time }}</div>
        </div>
    </div>
</template>

<style scoped>
.af-list { display: flex; flex-direction: column; }

.af-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.06));
}

.af-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.af-icon {
    width: 32px;
    height: 32px;
    min-width: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.af-body { flex: 1; min-width: 0; }

.af-desc {
    font-size: 13.5px;
    color: var(--dt-text, #C8E2EF);
    font-weight: 500;
}

.af-detail {
    font-size: 12px;
    color: var(--dt-muted, #6B8CAE);
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.af-time {
    font-size: 11px;
    color: var(--dt-muted, #6B8CAE);
    white-space: nowrap;
    flex-shrink: 0;
    padding-top: 2px;
}
</style>
