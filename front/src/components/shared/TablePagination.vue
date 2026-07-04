<script setup lang="ts">
defineProps<{
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
}>();

const emit = defineEmits<{ (e: 'change', page: number): void }>();
</script>

<template>
    <div v-if="lastPage > 1" class="tp-wrap">
        <span class="tp-info">
            Mostrando {{ (currentPage - 1) * perPage + 1 }}–{{ Math.min(currentPage * perPage, total) }} de {{ total }}
        </span>
        <div class="tp-btns">
            <button
                class="tp-btn"
                :disabled="currentPage === 1"
                @click="emit('change', currentPage - 1)"
            >‹</button>
            <button
                v-for="p in lastPage"
                :key="p"
                class="tp-btn"
                :class="{ 'is-active': p === currentPage }"
                @click="emit('change', p)"
            >{{ p }}</button>
            <button
                class="tp-btn"
                :disabled="currentPage === lastPage"
                @click="emit('change', currentPage + 1)"
            >›</button>
        </div>
    </div>
</template>

<style scoped>
.tp-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 0 0;
    gap: 12px;
    flex-wrap: wrap;
}

.tp-info {
    font-size: 12px;
    color: var(--dt-muted, #6B8CAE);
}

.tp-btns { display: flex; gap: 4px; }

.tp-btn {
    min-width: 32px;
    height: 32px;
    padding: 0 8px;
    border-radius: 8px;
    border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
    background: transparent;
    color: var(--dt-text, #C8E2EF);
    font-size: 13px;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.tp-btn:hover:not(:disabled) { background: var(--dt-hover, rgba(26,229,160,0.06)); }
.tp-btn:disabled { opacity: 0.35; cursor: default; }
.tp-btn.is-active {
    background: var(--dt-accent, #1AE5A0);
    color: #060F1C;
    border-color: var(--dt-accent, #1AE5A0);
    font-weight: 700;
}
</style>
