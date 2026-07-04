<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="page-header">
        <div class="ph-text">
            <h2 class="ph-title">{{ title }}</h2>
            <p v-if="subtitle" class="ph-subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="ph-actions">
            <slot name="actions" />
        </div>
    </div>
</template>

<style scoped>
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.ph-title {
    font-family:    'Syne', sans-serif;
    font-size:      20px;
    font-weight:    700;
    letter-spacing: -0.02em;
    color:          var(--dt-title, #fff);
    margin:         0;
    line-height:    1.3;
}

.ph-subtitle {
    font-size: 13px;
    color: var(--dt-muted, #6B8CAE);
    margin: 4px 0 0;
}

.ph-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
</style>
