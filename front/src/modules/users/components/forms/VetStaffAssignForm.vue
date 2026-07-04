<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { vetStaffAssignSchema } from '../../validators/vet-staff.validator'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import type { VetStaffAssignForm } from '../../validators/vet-staff.validator'
import type { ContactFormItem } from '@/modules/vets/types/vet.types'

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
  submit: [values: VetStaffAssignForm & { user_guid: string }]
  cancel: []
}>()

const { errors, defineField, handleSubmit, setErrors } = useForm<VetStaffAssignForm>({
  validationSchema: toTypedSchema(vetStaffAssignSchema),
})

const [role_guid, roleGuidAttrs] = defineField('role_guid')

const localContacts = ref<ContactFormItem[]>([])

const rolesStore = useRolesCacheStore()
rolesStore.fetchTenantRoles()

const roleOptions = computed(() => rolesStore.vetRoles)
const isLoadingRoles = computed(() => rolesStore.loading)

watch(
  () => props.fieldErrors,
  (errs) => { setErrors(errs ?? {}) },
)

const onSubmit = handleSubmit((values) => {
  emit('submit', {
    ...values,
    user_guid: props.user.guid,
    contacts: localContacts.value,
  })
})
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <div class="clf-card">
      <dl class="clf-dl">
        <dt>Nombre</dt>
        <dd>{{ user.first_name }} {{ user.last_name }}</dd>
        <dt>Email</dt>
        <dd>{{ user.email }}</dd>
      </dl>
    </div>

    <FormSection title="Rol en el equipo">
      <a-form-item
        label="Rol"
        :validate-status="errors.role_guid ? 'error' : ''"
        :help="errors.role_guid ?? ''"
      >
        <a-select
          v-model:value="role_guid"
          v-bind="roleGuidAttrs"
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

    <div class="clf-actions">
      <BaseButton
        variant="primary"
        html-type="submit"
        :loading="loading"
      >
        Incorporar al equipo
      </BaseButton>
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
    </div>
  </a-form>
</template>

<style scoped>
.clf-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
}

.clf-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 20px;
  margin: 0;
}

.clf-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.clf-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
}

.clf-actions {
  display: flex;
  gap: 10px;
}
</style>
