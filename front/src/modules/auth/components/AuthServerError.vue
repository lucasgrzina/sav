<script setup lang="ts">
defineProps<{ message?: string | null }>();
</script>

<template>
    <transition name="fade">
        <div v-if="message" class="auth-server-error">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0; margin-top:1px; color:#FF5A6A">
                <path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1Zm.75 10.5h-1.5V10h1.5v1.5Zm0-3h-1.5V4h1.5v4.5Z"/>
            </svg>
            {{ message }}
        </div>
    </transition>
</template>

<style scoped>
.fade-enter-active { transition: all 0.25s ease; }
.fade-enter-from  { opacity: 0; transform: translateY(-6px); }
.fade-enter-to    { opacity: 1; transform: translateY(0); }
</style>
