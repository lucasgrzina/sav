<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import type { ClientStaffContactFormItem } from '../../types/client.types'

const props = withDefaults(
  defineProps<{
    user: {
      guid: string
      first_name: string
      last_name: string
      email: string
    }
    loading?: boolean
    fieldErrors?: Record<string, string> | null
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  submit: [values: { user_guid: string; role_guid: string; contacts: ClientStaffContactFormItem[] }]
  cancel: []
}>()

const roleGuid      = ref<string>('')
const localContacts = ref<ClientStaffContactFormItem[]>([])

const rolesStore = useRolesCacheStore()
rolesStore.fetchTenantRoles()

const roleOptions = computed(() => rolesStore.clientRoles)
const isLoadingRoles = computed(() => rolesStore.loading)

watch(
  () => props.fieldErrors,
  (errs) => {
    if (errs?.role_guid) roleGuid.value = ''
  },
)

function onSubmit(): void {
  if (!roleGuid.value) return
  emit('submit', {
    user_guid: props.user.guid,
    role_guid: roleGuid.value,
    contacts:  localContacts.value,
  })
}
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <div class="csaf-card">
      <dl class="csaf-dl">
        <dt>Nombre</dt>
        <dd>{{ user.first_name }} {{ user.last_name }}</dd>
        <dt>Email</dt>
        <dd>{{ user.email }}</dd>
      </dl>
    </div>

    <FormSection title="Rol en el equipo">
      <a-form-item
        label="Rol"
        :validate-status="fieldErrors?.role_guid ? 'error' : ''"
        :help="fieldErrors?.role_guid || undefined"
      >
        <a-select
          v-model:value="roleGuid"
          :loading="isLoadingRoles"
          :options="roleOptions"
          placeholder="Seleccioná un rol"
          style="width: 100%"
        />
      </a-form-item>
    </FormSection>

    <FormSection title="Contactos de acceso" subtitle="Emails y teléfonos de contacto.">
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <div class="csaf-actions">
      <BaseButton
        variant="primary"
        html-type="submit"
        :loading="loading"
        :disabled="!roleGuid"
      >
        Incorporar al equipo
      </BaseButton>
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
    </div>
  </a-form>
</template>

<style scoped>
.csaf-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
}

.csaf-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 20px;
  margin: 0;
}

.csaf-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.csaf-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
}

.csaf-actions {
  display: flex;
  gap: 10px;
}
</style>
