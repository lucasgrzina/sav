<script setup lang="ts">
defineProps<{
    message?: string;
    icon?: string;
}>();
</script>

<template>
    <div class="empty-state">
        <div class="es-icon">{{ icon ?? '📭' }}</div>
        <p class="es-message">{{ message ?? 'No hay datos para mostrar.' }}</p>
        <slot />
    </div>
</template>

<style scoped>
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 24px;
    gap: 12px;
    text-align: center;
}

.es-icon { font-size: 40px; line-height: 1; }

.es-message {
    font-size: 14px;
    color: var(--dt-muted, #6B8CAE);
    margin: 0;
}
</style>
