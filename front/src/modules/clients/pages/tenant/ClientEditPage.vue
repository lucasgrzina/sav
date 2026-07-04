<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientForm from '../../components/forms/ClientForm.vue'
import { useClient } from '../../composables/useClient'
import { useUpdateClient } from '../../composables/useUpdateClient'
import type { ClientUpdateForm } from '../../validators/client.validator'

const props = defineProps<{ guid: string }>()

const router  = useRouter()
const route   = useRoute()
const vetGuid = computed(() => route.params.vetGuid as string)

const { data: client, isLoading } = useClient(computed(() => props.guid))
const { mutate, isPending, fieldErrors, generalError } = useUpdateClient()

function handleSubmit(values: ClientUpdateForm): void {
  mutate(
    { guid: props.guid, payload: values },
    { onSuccess: () => router.push(`/vets/${vetGuid.value}/clients/${props.guid}`) },
  )
}
</script>

<template>
  <div>
    <div class="cep-header">
      <BaseButton variant="tertiary" @click="router.push(`/vets/${vetGuid}/clients/${guid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al detalle
      </BaseButton>
    </div>

    <AppHeader
      title="Editar cliente"
      subtitle="Modificá los datos básicos del cliente."
      size="default"
    />

    <div v-if="isLoading" class="cep-loading">
      Cargando cliente...
    </div>

    <template v-else>
      <div v-if="generalError && !fieldErrors" class="cep-error-alert">
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
.cep-header { margin-bottom: 16px; }

.cep-back {
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
.cep-back:hover { color: var(--dt-accent, #1AE5A0); }

.cep-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.cep-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
