<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientForm from '../../components/forms/ClientForm.vue'
import { useAdminClient } from '../../composables/admin/useAdminClient'
import { useAdminUpdateClient } from '../../composables/admin/useAdminUpdateClient'
import type { ClientCreateForm, ClientUpdateForm } from '../../validators/client.validator'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { data: client, isLoading } = useAdminClient(computed(() => props.guid))
const { mutate, isPending, fieldErrors, generalError } = useAdminUpdateClient()

function handleSubmit(values: ClientCreateForm | ClientUpdateForm): void {
  mutate(
    { guid: props.guid, payload: values as ClientUpdateForm },
    { onSuccess: () => router.push(`/admin/clients/${props.guid}`) },
  )
}
</script>

<template>
  <div>
    <div class="acep-header">
      <BaseButton variant="tertiary" @click="router.push(`/admin/clients/${props.guid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al detalle
      </BaseButton>
    </div>

    <AppHeader
      title="Editar cliente"
      subtitle="Modificá los datos básicos del cliente."
      size="default"
    />

    <div v-if="isLoading" class="acep-loading">
      Cargando cliente...
    </div>

    <template v-else>
      <div v-if="generalError && !fieldErrors" class="acep-error-alert">
        {{ generalError }}
      </div>

      <ClientForm
        mode="edit"
        :initial-values="client"
        :loading="isPending"
        :field-errors="fieldErrors"
        @submit="handleSubmit"
      />
    </template>
  </div>
</template>

<style scoped>
.acep-header { margin-bottom: 16px; }

.acep-back {
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
.acep-back:hover { color: var(--dt-accent, #1AE5A0); }

.acep-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.acep-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
