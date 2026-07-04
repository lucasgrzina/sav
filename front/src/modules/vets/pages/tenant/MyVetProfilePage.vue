<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useMyVetProfile }       from '@/modules/vets/composables/useMyVetProfile'
import { useUpdateMyVetProfile } from '@/modules/vets/composables/useUpdateMyVetProfile'
import VetStaffEditForm          from '@/modules/vets/components/forms/VetStaffEditForm.vue'
import type { UpdateMyVetProfilePayload } from '@/modules/vets/types/vet.types'

const route   = useRoute()
const vetGuid = computed(() => route.params.vetGuid as string)

const { data: member, isLoading, isError } = useMyVetProfile(vetGuid)
const { mutate, isPending }                = useUpdateMyVetProfile(vetGuid)

function handleSubmit(payload: unknown): void {
  mutate(payload as UpdateMyVetProfilePayload)
}
</script>

<template>
  <div>
    <AppHeader
      title="Mi perfil"
      subtitle="Gestioná tu información personal en esta veterinaria."
      size="default"
    />

    <div v-if="isLoading" class="mvp-loading">
      Cargando perfil...
    </div>

    <a-result
      v-else-if="isError"
      status="error"
      title="No se pudo cargar el perfil"
      sub-title="Ocurrió un error al obtener tu perfil."
    />

    <VetStaffEditForm
      v-else-if="member"
      origin="self"
      :member="member"
      :is-pending="isPending"
      @submit="handleSubmit"
    />
  </div>
</template>

<style scoped>
.mvp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
