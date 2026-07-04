<script setup lang="ts">
import { useRouter } from 'vue-router'
import VetForm from '../../components/forms/VetForm.vue'
import { useCreateVet } from '../../composables/useCreateVet'
import type { VetCreateForm } from '../../validators/vet.validator'

const router = useRouter()
const { mutate, isPending, fieldErrors, generalError } = useCreateVet()

function handleSubmit(values: VetCreateForm) {
  mutate(values, {
    onSuccess: () => router.push('/admin/vets'),
  })
}
</script>

<template>
  <div>
    <AppHeader
      title="Nueva veterinaria"
      subtitle="Completá los datos para registrar una nueva veterinaria en el sistema."
      size="default"
    />

    <div v-if="generalError && !fieldErrors" class="vc-error-alert">
      {{ generalError }}
    </div>

    <VetForm
      origin="admin"
      mode="create"
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
    />
  </div>
</template>

<style scoped>
.vc-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
