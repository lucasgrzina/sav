<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useClientStaffMember } from '@/modules/clients/composables/useClientStaffMember'
import { useClientRoles }       from '@/modules/clients/composables/useClientRoles'
import { useUpdateClientStaff } from '@/modules/clients/composables/useUpdateClientStaff'
import ClientStaffEditForm      from '@/modules/clients/components/forms/ClientStaffEditForm.vue'
import type { UpdateClientStaffPayload } from '@/modules/clients/types/client.types'

const router      = useRouter()
const route       = useRoute()
const vetGuid     = computed(() => route.params.vetGuid as string)
const clientGuid  = computed(() => route.params.clientGuid as string)
const profileGuid = computed(() => route.params.profileGuid as string)

const { data: member, isLoading, isError } = useClientStaffMember(vetGuid, clientGuid, profileGuid)
const { clientRoles, isLoading: isLoadingRoles } = useClientRoles()
const { mutate, isPending } = useUpdateClientStaff(vetGuid, clientGuid)

function handleSubmit(payload: UpdateClientStaffPayload): void {
  mutate(
    { profileGuid: profileGuid.value, payload },
    { onSuccess: () => router.push(`/vets/${vetGuid.value}/clients/${clientGuid.value}`) },
  )
}
</script>

<template>
  <div>
    <div class="cesp-header">
      <BaseButton variant="tertiary" @click="router.push(`/vets/${vetGuid}/clients/${clientGuid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al cliente
      </BaseButton>
    </div>

    <AppHeader
      title="Editar perfil de staff"
      :subtitle="member?.user.name ?? ''"
      size="default"
    />

    <div v-if="isLoading" class="cesp-loading">
      Cargando perfil...
    </div>

    <a-result
      v-else-if="isError"
      status="error"
      title="No se pudo cargar el perfil"
      sub-title="El miembro no fue encontrado o no tenés acceso."
    />

    <ClientStaffEditForm
      v-else-if="member"
      :member="member"
      :client-roles="clientRoles"
      :is-loading-roles="isLoadingRoles"
      :is-pending="isPending"
      @submit="handleSubmit"
    />
  </div>
</template>

<style scoped>
.cesp-header { margin-bottom: 16px; }
.cesp-back {
  background: none; border: none; color: var(--dt-muted, #6B8CAE); font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 0; transition: color 0.15s;
}
.cesp-back:hover { color: var(--dt-accent, #1AE5A0); }

.cesp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
