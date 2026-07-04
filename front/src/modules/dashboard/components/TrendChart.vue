<script setup lang="ts">
import { computed, getCurrentInstance } from 'vue';

const props = withDefaults(defineProps<{
    data: number[];
    color?: string;
    labels?: string[];
}>(), {
    color: '#1AE5A0',
});

const uid     = getCurrentInstance()!.uid;
const gradId  = computed(() => `tc-grad-${uid}`);
const maxVal  = computed(() => Math.max(...props.data, 1));
const N       = computed(() => props.data.length);
const slotW   = computed(() => 100 / N.value);

const bars = computed(() =>
    props.data.map((v, i) => {
        const barH = (v / maxVal.value) * 56;
        return {
            x:    i * slotW.value + slotW.value * 0.18,
            y:    60 - Math.max(barH, 2),
            w:    slotW.value * 0.64,
            h:    Math.max(barH, 2),
            last: i === N.value - 1,
        };
    })
);
</script>

<template>
    <div class="trend-chart">
        <svg width="100%" height="72" viewBox="0 0 100 60" preserveAspectRatio="none">
            <defs>
                <linearGradient :id="gradId" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%"   :stop-color="color" stop-opacity="1" />
                    <stop offset="100%" :stop-color="color" stop-opacity="0.25" />
                </linearGradient>
            </defs>
            <rect
                v-for="(bar, i) in bars"
                :key="i"
                :x="bar.x"
                :y="bar.y"
                :width="bar.w"
                :height="bar.h"
                :fill="`url(#${gradId})`"
                :fill-opacity="bar.last ? 1 : 0.55"
                rx="1.8"
            />
        </svg>
        <div v-if="labels?.length" class="tc-labels">
            <span v-for="label in labels" :key="label">{{ label }}</span>
        </div>
    </div>
</template>

<style scoped>
.trend-chart { width: 100%; }

.tc-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 6px;
    padding: 0 2px;
}

.tc-labels span {
    font-size: 10px;
    color: var(--dt-muted, #6B8CAE);
}
</style>
