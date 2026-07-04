<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientStaffLookupForm from '@/modules/clients/components/forms/ClientStaffLookupForm.vue'

const router     = useRouter()
const route      = useRoute()
const vetGuid    = computed(() => route.params.vetGuid as string)
const clientGuid = computed(() => route.params.clientGuid as string)

function handleSuccess(): void {
  router.push(`/vets/${vetGuid.value}/clients/${clientGuid.value}`)
}
</script>

<template>
  <div>
    <div class="cscp-header">
      <BaseButton variant="tertiary" @click="router.push(`/vets/${vetGuid}/clients/${clientGuid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al cliente
      </BaseButton>
    </div>

    <AppHeader
      title="Agregar staff al cliente"
      subtitle="Buscá el usuario por email para incorporarlo, o completá los datos para crear uno nuevo."
      size="default"
    />

    <ClientStaffLookupForm @success="handleSuccess" />
  </div>
</template>

<style scoped>
.cscp-header { margin-bottom: 16px; }

.cscp-back {
  background: none;
  border: none;
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  transition: color 0.15s;
}
.cscp-back:hover { color: var(--dt-accent, #1AE5A0); }
</style>
