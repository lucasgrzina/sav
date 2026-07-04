<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import type { ClientStaffContactFormItem, ClientStaffCreatePayload } from '../../types/client.types'

const props = withDefaults(
  defineProps<{
    initialEmail: string
    loading?: boolean
    fieldErrors?: Record<string, string> | null
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  submit: [values: ClientStaffCreatePayload]
  cancel: []
}>()

const firstName     = ref<string>('')
const lastName      = ref<string>('')
const email         = ref<string>(props.initialEmail)
const roleGuid      = ref<string>('')
const localContacts = ref<ClientStaffContactFormItem[]>([])

const rolesStore = useRolesCacheStore()
rolesStore.fetchTenantRoles()

const roleOptions = computed(() => rolesStore.clientRoles)
const isLoadingRoles = computed(() => rolesStore.loading)

watch(
  () => props.initialEmail,
  (newEmail) => { email.value = newEmail },
  { immediate: true },
)

function onSubmit(): void {
  if (!firstName.value.trim() || !lastName.value.trim() || !email.value.trim() || !roleGuid.value) return
  emit('submit', {
    first_name: firstName.value.trim(),
    last_name:  lastName.value.trim(),
    email:      email.value.trim(),
    role_guid:  roleGuid.value,
    contacts:   localContacts.value,
  })
}
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <FormSection title="Datos del nuevo personal">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Nombre"
            :validate-status="fieldErrors?.first_name ? 'error' : ''"
            :help="fieldErrors?.first_name ?? ''"
          >
            <a-input
              v-model:value="firstName"
              placeholder="Ej: María"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Apellido"
            :validate-status="fieldErrors?.last_name ? 'error' : ''"
            :help="fieldErrors?.last_name ?? ''"
          >
            <a-input
              v-model:value="lastName"
              placeholder="Ej: López"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Email"
            :validate-status="fieldErrors?.email ? 'error' : ''"
            :help="fieldErrors?.email ?? ''"
          >
            <a-input
              v-model:value="email"
              placeholder="correo@ejemplo.com"
              disabled
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Rol"
            :validate-status="fieldErrors?.role_guid ? 'error' : ''"
            :help="fieldErrors?.role_guid ?? ''"
          >
            <a-select
              v-model:value="roleGuid"
              :loading="isLoadingRoles"
              :options="roleOptions"
              placeholder="Seleccioná un rol"
              style="width: 100%"
            />
          </a-form-item>
        </a-col>
      </a-row>
    </FormSection>

    <FormSection title="Contactos de acceso" subtitle="Emails y teléfonos de contacto.">
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <div class="csnf-actions">
      <BaseButton
        variant="primary"
        html-type="submit"
        :loading="loading"
      >
        Crear usuario e incorporar
      </BaseButton>
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
    </div>
  </a-form>
</template>

<style scoped>
.csnf-actions {
  display: flex;
  gap: 10px;
  padding-top: 20px;
}
</style>
