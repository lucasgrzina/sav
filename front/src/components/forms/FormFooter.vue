<script setup lang="ts">
defineProps<{
    loading?: boolean;
    saveLabel?: string;
    cancelTo?: string;
}>();
</script>

<template>
    <div class="form-footer">
        <RouterLink v-if="cancelTo" :to="cancelTo" class="ff-btn ff-btn--cancel">
            Cancelar
        </RouterLink>
        <button
            type="submit"
            class="ff-btn ff-btn--save"
            :disabled="loading"
        >
            <span v-if="loading" class="ff-spinner" />
            {{ loading ? 'Guardando...' : (saveLabel ?? 'Guardar') }}
        </button>
    </div>
</template>

<style scoped>
.form-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 20px;
}

.ff-btn {
    padding:         10px 24px;
    border-radius:   var(--dt-radius-md, 10px);
    font-family:     'Figtree', system-ui, sans-serif;
    font-size:       14px;
    font-weight:     600;
    cursor:          pointer;
    border:          none;
    text-decoration: none;
    display:         inline-flex;
    align-items:     center;
    gap:             8px;
    transition:      all 0.2s;
}

.ff-btn--save:hover:not(:disabled) {
    opacity:    0.88;
    box-shadow: 0 4px 16px rgba(26, 229, 160, 0.25);
}

.ff-btn:hover:not(:disabled) { opacity: 0.85; }
.ff-btn:disabled { opacity: 0.5; cursor: default; }

.ff-btn--cancel {
    background: transparent;
    border: 1px solid var(--dt-border, rgba(26,229,160,0.2));
    color: var(--dt-muted, #6B8CAE);
}

.ff-btn--save {
    background: var(--dt-accent, #1AE5A0);
    color: #060F1C;
}

.ff-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(6,15,28,0.3);
    border-top-color: #060F1C;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    flex-shrink: 0;
}

@keyframes spin { to { transform: rotate(360deg); } }
</style>
