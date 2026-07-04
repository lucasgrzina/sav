<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import { getRoleLabel } from '@/core/utils/roles'
import type {
  VetStaffItem,
  VetStaffRoleItem,
  ContactFormItem,
  UpdateVetStaffPayload,
  UpdateMyVetProfilePayload,
} from '../../types/vet.types'

const props = withDefaults(
  defineProps<{
    origin: 'manage' | 'self'
    member: VetStaffItem
    isPending: boolean
    vetRoles?: VetStaffRoleItem[]
    isLoadingRoles?: boolean
  }>(),
  { vetRoles: () => [], isLoadingRoles: false },
)

const emit = defineEmits<{
  submit: [payload: UpdateVetStaffPayload | UpdateMyVetProfilePayload]
}>()

const selectedRoleGuid = shallowRef('')
const firstName        = ref('')
const lastName         = ref('')
const localContacts    = ref<ContactFormItem[]>([])

watch(
  () => props.member,
  (m) => {
    if (!m) return
    selectedRoleGuid.value = m.role.guid
    firstName.value        = m.user.first_name
    lastName.value         = m.user.last_name
    localContacts.value    = m.contacts.map((c) => ({
      guid:           c.guid,
      type:           c.type,
      value:          c.value,
      label:          c.label,
      is_primary:     c.is_primary,
      use_for_alerts: c.use_for_alerts,
    }))
  },
  { immediate: true },
)

function onSubmit(): void {
  if (props.origin === 'manage') {
    emit('submit', { role_guid: selectedRoleGuid.value, contacts: localContacts.value })
  } else {
    emit('submit', { first_name: firstName.value, last_name: lastName.value, contacts: localContacts.value })
  }
}
</script>

<template>
  <a-form class="vsef-form" layout="vertical" @submit.prevent="onSubmit">

    <!-- manage: muestra nombre completo + email deshabilitados -->
    <template v-if="origin === 'manage'">
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
    </template>

    <!-- self: nombre y apellido editables, email deshabilitado -->
    <template v-else>
      <FormSection
        title="Datos personales"
        subtitle="Tu nombre como aparecerá en el sistema."
      >
        <a-row :gutter="[16, 0]">
          <a-col :xs="24" :sm="12">
            <a-form-item label="Nombre">
              <a-input v-model:value="firstName" placeholder="Tu nombre" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item label="Apellido">
              <a-input v-model:value="lastName" placeholder="Tu apellido" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item label="Email">
              <a-input :value="member.user.email" disabled />
            </a-form-item>
          </a-col>
        </a-row>
      </FormSection>
    </template>

    <!-- manage: rol editable via select -->
    <template v-if="origin === 'manage'">
      <FormSection title="Rol en esta veterinaria">
        <a-form-item label="Rol">
          <a-select
            v-model:value="selectedRoleGuid"
            :loading="isLoadingRoles"
            :disabled="isLoadingRoles"
            style="width: 100%; max-width: 320px"
          >
            <a-select-option
              v-for="role in vetRoles"
              :key="role.guid"
              :value="role.guid"
            >
              {{ getRoleLabel(role.name) }}
            </a-select-option>
          </a-select>
        </a-form-item>
      </FormSection>
    </template>

    <!-- self: rol deshabilitado (solo lectura) -->
    <template v-else>
      <FormSection title="Rol en esta veterinaria">
        <a-form-item label="Rol">
          <a-input :value="getRoleLabel(member.role.name)" disabled style="max-width: 320px" />
        </a-form-item>
      </FormSection>
    </template>

    <FormSection
      title="Contactos"
      subtitle="Teléfonos y emails de contacto en esta veterinaria."
    >
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <FormFooter save-label="Guardar cambios" :loading="isPending" />
  </a-form>
</template>

<style scoped>
.vsef-form { display: flex; flex-direction: column; gap: 20px; }
</style>
