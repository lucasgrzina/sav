<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import { getRoleLabel } from '@/core/utils/roles'
import type { ClientStaffItem, ClientStaffRoleItem, ClientStaffContactFormItem, UpdateClientStaffPayload } from '../../types/client.types'

const props = defineProps<{
  member: ClientStaffItem
  clientRoles: ClientStaffRoleItem[]
  isLoadingRoles: boolean
  isPending: boolean
}>()

const emit = defineEmits<{
  submit: [payload: UpdateClientStaffPayload]
}>()

const selectedRoleGuid = shallowRef<string>('')
const localContacts = ref<ClientStaffContactFormItem[]>([])

watch(
  () => props.member,
  (m) => {
    if (!m) return
    selectedRoleGuid.value = m.role.guid
    localContacts.value = m.contacts.map((c) => ({
      guid: c.guid,
      type: c.type as 'email' | 'phone' | 'whatsapp',
      value: c.value,
      label: c.label,
      is_primary: c.is_primary,
      use_for_alerts: c.use_for_alerts,
    }))
  },
  { immediate: true },
)

function onSubmit(): void {
  emit('submit', {
    role_guid: selectedRoleGuid.value,
    contacts: localContacts.value,
  })
}
</script>

<template>
  <a-form class="csef-form" layout="vertical" @submit.prevent="onSubmit">
    <FormSection
      title="Datos del usuario"
      subtitle="Los datos del usuario no se pueden modificar desde aquí."
    >
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item label="Nombre completo">
            <a-input :value="member.user.name" disabled />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item label="Email">
            <a-input :value="member.user.email" disabled />
          </a-form-item>
        </a-col>
      </a-row>
    </FormSection>

    <FormSection title="Rol en este cliente">
      <a-form-item label="Rol">
        <a-select
          v-model:value="selectedRoleGuid"
          :loading="isLoadingRoles"
          :disabled="isLoadingRoles"
          style="width: 100%; max-width: 320px"
        >
          <a-select-option
            v-for="role in clientRoles"
            :key="role.guid"
            :value="role.guid"
          >
            {{ getRoleLabel(role.name) }}
          </a-select-option>
        </a-select>
      </a-form-item>
    </FormSection>

    <FormSection title="Contactos" subtitle="Teléfonos y emails de contacto de este usuario en el cliente.">
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <FormFooter save-label="Guardar cambios" :loading="isPending" />
  </a-form>
</template>

<style scoped>
.csef-form { display: flex; flex-direction: column; gap: 20px; }
</style>
