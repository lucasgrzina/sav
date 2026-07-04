<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useAdminClientStaffMember }  from '@/modules/clients/composables/admin/useAdminClientStaffMember'
import { useAdminUpdateClientStaff }  from '@/modules/clients/composables/admin/useAdminUpdateClientStaff'
import { useClientRoles }             from '@/modules/clients/composables/useClientRoles'
import ClientStaffEditForm            from '@/modules/clients/components/forms/ClientStaffEditForm.vue'
import type { UpdateClientStaffPayload } from '@/modules/clients/types/client.types'

const props = defineProps<{ guid: string; profileGuid: string }>()

const router = useRouter()

const { data: member, isLoading, isError } = useAdminClientStaffMember(
  computed(() => props.guid),
  computed(() => props.profileGuid),
)

const { clientRoles, isLoading: isLoadingRoles } = useClientRoles()
const { mutate, isPending } = useAdminUpdateClientStaff(computed(() => props.guid))

function handleSubmit(payload: UpdateClientStaffPayload): void {
  mutate(
    { profileGuid: props.profileGuid, payload },
    { onSuccess: () => router.push(`/admin/clients/${props.guid}`) },
  )
}
</script>

<template>
  <div>
    <div class="acesp-header">
      <BaseButton variant="tertiary" @click="router.push(`/admin/clients/${guid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al cliente
      </BaseButton>
    </div>

    <AppHeader
      title="Editar miembro de staff"
      :subtitle="member?.user.name ?? ''"
      size="default"
    />

    <div v-if="isLoading" class="acesp-loading">
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
.acesp-header { margin-bottom: 16px; }
.acesp-back {
  background: none; border: none; color: var(--dt-muted, #6B8CAE); font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 0; transition: color 0.15s;
}
.acesp-back:hover { color: var(--dt-accent, #1AE5A0); }

.acesp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
