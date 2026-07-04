<script setup lang="ts">
import { useConfirm } from '@/core/composables/useConfirm';
import BaseConfirmDialog from '../atoms/overlays/BaseConfirmDialog.vue';
const { visible, loading, options, accept, cancel } = useConfirm();
</script>

<template>
    <Teleport to="body">
        <BaseConfirmDialog
            v-model="visible"
            :title="options.title ?? 'Confirmar acción'"
            :message="options.message"
            :danger="options.danger ?? false"
            :loading="loading"
            :ok-text="options.confirmLabel ?? 'Confirmar'"
            @confirm="accept"
            :cancel-text="options.cancelLabel ?? 'Cancelar'"
            @cancel="cancel"
            
        />
        <!--Transition name="dialog-fade">
            <div v-if="visible" class="cd-overlay" @click.self="cancel">
                <div class="cd-box" role="dialog" aria-modal="true">
                    <p class="cd-title">{{ options.title ?? 'Confirmar acción' }}</p>
                    <p class="cd-message">{{ options.message }}</p>
                    <div class="cd-footer">
                        <button class="cd-btn cd-btn--cancel" @click="cancel">
                            {{ options.cancelLabel ?? 'Cancelar' }}
                        </button>
                        <button
                            class="cd-btn"
                            :class="options.danger ? 'cd-btn--danger' : 'cd-btn--confirm'"
                            @click="accept"
                        >
                            {{ options.confirmLabel ?? 'Confirmar' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition-->
    </Teleport>
</template>

<style scoped>
.cd-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 16px;
}

.cd-box {
    background: var(--dt-card, #0E2038);
    border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
    border-radius: 16px;
    padding: 28px 28px 24px;
    max-width: 420px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
}

.cd-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--dt-title, #fff);
    margin: 0 0 10px;
}

.cd-message {
    font-size: 14px;
    color: var(--dt-text, #C8E2EF);
    margin: 0 0 24px;
    line-height: 1.5;
}

.cd-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.cd-btn {
    padding: 9px 20px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: opacity 0.15s;
}

.cd-btn:hover { opacity: 0.85; }

.cd-btn--cancel {
    background: transparent;
    border-color: var(--dt-border, rgba(26,229,160,0.2));
    color: var(--dt-muted, #6B8CAE);
}

.cd-btn--confirm {
    background: var(--dt-accent, #1AE5A0);
    color: #060F1C;
}

.cd-btn--danger {
    background: #FF5A6A;
    color: #fff;
}

.dialog-fade-enter-active,
.dialog-fade-leave-active { transition: opacity 0.2s ease; }
.dialog-fade-enter-from,
.dialog-fade-leave-to    { opacity: 0; }
</style>
