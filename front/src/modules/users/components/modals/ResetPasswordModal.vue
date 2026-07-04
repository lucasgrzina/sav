<script setup lang="ts">
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

const props = defineProps<{
    password: string
}>()
const isOpen = defineModel<boolean>({ default: false })


function close() {
    isOpen.value = false
}
</script>

<template>
    <BaseModal v-model="isOpen" title="Contraseña generada" :width="500">
        <a-alert message="Se generó una nueva contraseña para el usuario. Guardala antes de cerrar esta ventana."
            type="info" show-icon style="margin-bottom: 16px" />
        <a-typography-paragraph>
            <a-typography-text strong>Nueva contraseña:</a-typography-text>
        </a-typography-paragraph>
        <a-typography-paragraph :copyable="{ text: password }">
            <a-typography-text code style="font-size: 16px">{{ password }}</a-typography-text>
        </a-typography-paragraph>
        <div style="text-align: right; margin-top: 16px">
            <BaseButton variant="primary" @click="close">Entendido</BaseButton>
        </div>
    </BaseModal>
</template>
