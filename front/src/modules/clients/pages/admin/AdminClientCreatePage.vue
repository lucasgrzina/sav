<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientForm from '../../components/forms/ClientForm.vue'
import { useAdminCreateClient } from '../../composables/admin/useAdminCreateClient'
import type { ClientCreateForm, ClientUpdateForm } from '../../validators/client.validator'

const router = useRouter()
const { mutate, isPending, fieldErrors, generalError } = useAdminCreateClient()

function handleSubmit(values: ClientCreateForm | ClientUpdateForm): void {
  mutate(values as ClientCreateForm, {
    onSuccess: (client) => {
      router.push(`/admin/clients/${client.guid}`)
    },
  })
}
</script>

<template>
  <div>
    <div class="accp-header">
      <BaseButton variant="tertiary" @click="router.push('/admin/clients')">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a clientes
      </BaseButton>
    </div>

    <AppHeader
      title="Nuevo cliente"
      subtitle="Creá un cliente sin asignarlo a ninguna veterinaria."
      size="default"
    />

    <div v-if="generalError && !fieldErrors" class="accp-error-alert">
      {{ generalError }}
    </div>

    <ClientForm
      mode="create"
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
    />
  </div>
</template>

<style scoped>
.accp-header { margin-bottom: 16px; }

.accp-back {
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
.accp-back:hover { color: var(--dt-accent, #1AE5A0); }

.accp-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
