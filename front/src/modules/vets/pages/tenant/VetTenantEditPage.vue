<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useVetStore } from '@/core/stores/vet.store'
import { useUpdateVetTenant } from '@/modules/vets/composables/useUpdateVetTenant'
import VetForm from '@/modules/vets/components/forms/VetForm.vue'
import type { VetUpdatePayload } from '@/modules/vets/types/vet.types'

const router  = useRouter()
const route   = useRoute()
const vetGuid = computed(() => route.params.vetGuid as string)

const vetStore = useVetStore()
const vet      = computed(() => vetStore.currentVet)

const perfilPath = computed(() => `/vets/${vetGuid.value}/perfil`)

const { mutate, isPending, fieldErrors, generalError } = useUpdateVetTenant()

function handleSubmit(payload: unknown) {
  mutate(
    { guid: vetGuid.value, payload },
    { onSuccess: () => router.push(perfilPath.value) },
  )
}
</script>

<template>
  <div>
    <div class="vte-header">
      <BaseButton variant="tertiary" @click="router.push(perfilPath)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al perfil
      </BaseButton>
    </div>

    <AppHeader
      title="Editar perfil de la veterinaria"
      :subtitle="vet?.name ?? ''"
      size="default"
    />

    <div v-if="!vet" class="vte-loading">
      Cargando datos...
    </div>

    <template v-else>
      <div v-if="generalError && !fieldErrors" class="vte-error">
        {{ generalError }}
      </div>

      <VetForm
        origin="tenant"
        :initial-values="vet"
        :loading="isPending"
        :field-errors="fieldErrors"
        :cancel-to="perfilPath"
        @submit="handleSubmit"
      />
    </template>
  </div>
</template>

<style scoped>
.vte-header { margin-bottom: 16px; }

.vte-back {
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
.vte-back:hover { color: var(--dt-accent, #1AE5A0); }

.vte-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.vte-error {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
