<script setup lang="ts">
defineProps<{
    label: string;
    error?: string;
}>();
</script>

<template>
    <div class="auth-field-wrap">
        <label class="auth-field-label">{{ label }}</label>
        <slot />
        <transition name="err">
            <span v-if="error" class="auth-field-error">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor" style="flex-shrink:0; margin-top:1px">
                    <path d="M6 0a6 6 0 1 0 0 12A6 6 0 0 0 6 0Zm.75 9H5.25V7.5h1.5V9Zm0-3H5.25V3h1.5v3Z"/>
                </svg>
                {{ error }}
            </span>
        </transition>
    </div>
</template>

<style scoped>
.auth-field-wrap { display: flex; flex-direction: column; margin-bottom: 18px; }

.err-enter-active { transition: all 0.2s ease; }
.err-enter-from  { opacity: 0; transform: translateY(-4px); }
.err-enter-to    { opacity: 1; transform: translateY(0); }
</style>
