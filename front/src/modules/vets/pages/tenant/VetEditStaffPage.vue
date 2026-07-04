<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useVetStaffMember } from '@/modules/vets/composables/useVetStaffMember'
import { useVetRoles }       from '@/modules/vets/composables/useVetRoles'
import { useUpdateVetStaff } from '@/modules/vets/composables/useUpdateVetStaff'
import VetStaffEditForm      from '@/modules/vets/components/forms/VetStaffEditForm.vue'
import type { UpdateVetStaffPayload } from '@/modules/vets/types/vet.types'

const router      = useRouter()
const route       = useRoute()
const vetGuid     = computed(() => route.params.vetGuid as string)
const profileGuid = computed(() => route.params.profileGuid as string)

const { data: member, isLoading, isError } = useVetStaffMember(vetGuid, profileGuid)
const { vetRoles, isLoading: isLoadingRoles } = useVetRoles()
const { mutate, isPending } = useUpdateVetStaff(vetGuid)

function handleSubmit(payload: unknown): void {
  mutate(
    { profileGuid: profileGuid.value, payload },
    { onSuccess: () => router.push(`/vets/${vetGuid.value}/usuarios`) },
  )
}
</script>

<template>
  <div>
    <div class="esp-header">
      <BaseButton variant="tertiary" @click="router.push(`/vets/${vetGuid}/usuarios`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a usuarios
      </BaseButton>
    </div>

    <AppHeader
      title="Editar perfil de usuario"
      :subtitle="member?.user.name ?? ''"
      size="default"
    />

    <div v-if="isLoading" class="esp-loading">
      Cargando perfil...
    </div>

    <a-result
      v-else-if="isError"
      status="error"
      title="No se pudo cargar el perfil"
      sub-title="El miembro no fue encontrado o no tenés acceso."
    />

    <VetStaffEditForm
      v-else-if="member"
      origin="manage"
      :member="member"
      :vet-roles="vetRoles"
      :is-loading-roles="isLoadingRoles"
      :is-pending="isPending"
      @submit="handleSubmit"
    />
  </div>
</template>

<style scoped>
.esp-header { margin-bottom: 16px; }
.esp-back {
  background: none; border: none; color: var(--dt-muted, #6B8CAE); font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 0; transition: color 0.15s;
}
.esp-back:hover { color: var(--dt-accent, #1AE5A0); }

.esp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
