<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import VetForm from '../../components/forms/VetForm.vue'
import { useVet } from '../../composables/useVet'
import { useUpdateVet } from '../../composables/useUpdateVet'
import type { VetUpdateForm } from '../../validators/vet.validator'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { data: vet, isLoading } = useVet(computed(() => props.guid))
const { mutate, isPending, fieldErrors, generalError } = useUpdateVet()

function handleSubmit(values: VetUpdateForm) {
  mutate(
    { guid: props.guid, payload: values },
    { onSuccess: () => router.push(`/admin/vets/${props.guid}`) },
  )
}
</script>

<template>
  <div>
    <AppHeader
      title="Editar veterinaria"
      subtitle="Modificá los datos de la veterinaria."
      size="default"
    />

    <div v-if="isLoading" class="ve-loading">
      Cargando veterinaria...
    </div>

    <template v-else>
      <div v-if="generalError && !fieldErrors" class="ve-error-alert">
        {{ generalError }}
      </div>

      <VetForm
        origin="admin"
        mode="edit"
        :initial-values="vet"
        :loading="isPending"
        :field-errors="fieldErrors"
        @submit="handleSubmit"
      />
    </template>
  </div>
</template>

<style scoped>
.ve-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.ve-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
