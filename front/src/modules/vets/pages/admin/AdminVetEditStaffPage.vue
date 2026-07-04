<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useAdminVetStaffMember }  from '@/modules/vets/composables/useAdminVetStaffMember'
import { useAdminUpdateVetStaff }  from '@/modules/vets/composables/useAdminUpdateVetStaff'
import { useVetRoles }             from '@/modules/vets/composables/useVetRoles'
import VetStaffEditForm            from '@/modules/vets/components/forms/VetStaffEditForm.vue'
import type { UpdateVetStaffPayload } from '@/modules/vets/types/vet.types'

const props = defineProps<{ guid: string; profileGuid: string }>()

const router = useRouter()

const { data: member, isLoading, isError } = useAdminVetStaffMember(
  computed(() => props.guid),
  computed(() => props.profileGuid),
)

const { vetRoles, isLoading: isLoadingRoles } = useVetRoles()
const { mutate, isPending } = useAdminUpdateVetStaff(computed(() => props.guid))

function handleSubmit(payload: unknown): void {
  mutate(
    { profileGuid: props.profileGuid, payload },
    { onSuccess: () => router.push(`/admin/vets/${props.guid}`) },
  )
}
</script>

<template>
  <div>
    <div class="avesp-header">
      <BaseButton variant="tertiary" @click="router.push(`/admin/vets/${guid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a la veterinaria
      </BaseButton>
    </div>

    <AppHeader
      title="Editar miembro de staff"
      :subtitle="member?.user.name ?? ''"
      size="default"
    />

    <div v-if="isLoading" class="avesp-loading">
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
.avesp-header { margin-bottom: 16px; }
.avesp-back {
  background: none; border: none; color: var(--dt-muted, #6B8CAE); font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 0; transition: color 0.15s;
}
.avesp-back:hover { color: var(--dt-accent, #1AE5A0); }

.avesp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
