<script setup lang="ts">
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientLookupForm from '../../components/forms/ClientLookupForm.vue'
import type { ClientItem } from '../../types/client.types'

const router  = useRouter()
const route   = useRoute()
const vetGuid = () => route.params.vetGuid as string

function handleSuccess(client: ClientItem): void {
  router.push(`/vets/${vetGuid()}/clients/${client.guid}`)
}
</script>

<template>
  <div>
    <div class="ccp-header">
      <BaseButton variant="tertiary" @click="router.push(`/vets/${vetGuid()}/clients`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a clientes
      </BaseButton>
    </div>

    <AppHeader
      title="Agregar cliente"
      subtitle="Buscá el cliente por CUIT para vincularlo, o completá los datos para crearlo."
      size="default"
    />

    <ClientLookupForm @success="handleSuccess" />
  </div>
</template>

<style scoped>
.ccp-header { margin-bottom: 16px; }

.ccp-back {
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
.ccp-back:hover { color: var(--dt-accent, #1AE5A0); }
</style>
