<script setup lang="ts">
defineProps<{
    modelValue: string;
    placeholder?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>();
</script>

<template>
    <div class="filter-bar">
        <div class="fb-search">
            <svg class="fb-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                type="text"
                class="fb-input"
                :value="modelValue"
                :placeholder="placeholder ?? 'Buscar...'"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />
        </div>
        <slot />
    </div>
</template>

<style scoped>
.filter-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.fb-search {
    position: relative;
    flex: 1;
    min-width: 200px;
    max-width: 360px;
}

.fb-icon {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--dt-muted, #6B8CAE);
    pointer-events: none;
}

.fb-input {
    width:         100%;
    padding:       9px 12px 9px 34px;
    border-radius: var(--dt-radius-md, 10px);
    border:        1px solid var(--dt-border, rgba(26,229,160,0.12));
    background:    var(--dt-card, #0E2038);
    color:         var(--dt-text, #C8E2EF);
    font-family:   'Figtree', system-ui, sans-serif;
    font-size:     13.5px;
    outline:       none;
    transition:    border-color 0.2s, box-shadow 0.2s;
}

.fb-input::placeholder { color: var(--dt-muted, #6B8CAE); opacity: 0.65; }
.fb-input:focus {
    border-color: var(--dt-accent, #1AE5A0);
    box-shadow:   0 0 0 3px rgba(26, 229, 160, 0.1);
}
</style>
