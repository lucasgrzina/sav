<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { vetStaffNewSchema } from '../../validators/vet-staff.validator'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import type { VetStaffNewForm } from '../../validators/vet-staff.validator'
import type { ContactFormItem } from '@/modules/vets/types/vet.types'

const props = withDefaults(
  defineProps<{
    initialEmail: string
    loading?: boolean
    fieldErrors?: Record<string, string> | null
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  submit: [values: VetStaffNewForm]
  cancel: []
}>()

const { errors, defineField, handleSubmit, setErrors, setFieldValue } = useForm<VetStaffNewForm>({
  validationSchema: toTypedSchema(vetStaffNewSchema),
})

const [first_name, firstNameAttrs] = defineField('first_name')
const [last_name, lastNameAttrs]   = defineField('last_name')
const [email, emailAttrs]          = defineField('email')
const [role_guid, roleGuidAttrs]   = defineField('role_guid')

const localContacts = ref<ContactFormItem[]>([])

const rolesStore = useRolesCacheStore()
rolesStore.fetchTenantRoles()

const roleOptions = computed(() => rolesStore.vetRoles)
const isLoadingRoles = computed(() => rolesStore.loading)

watch(
  () => props.initialEmail,
  (newEmail) => { setFieldValue('email', newEmail) },
  { immediate: true },
)

watch(
  () => props.fieldErrors,
  (errs) => { setErrors(errs ?? {}) },
)

const onSubmit = handleSubmit((values) => {
  emit('submit', { ...values, contacts: localContacts.value })
})
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <FormSection title="Datos del nuevo personal">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Nombre"
            :validate-status="errors.first_name ? 'error' : ''"
            :help="errors.first_name ?? ''"
          >
            <a-input
              v-model:value="first_name"
              v-bind="firstNameAttrs"
              placeholder="Ej: María"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Apellido"
            :validate-status="errors.last_name ? 'error' : ''"
            :help="errors.last_name ?? ''"
          >
            <a-input
              v-model:value="last_name"
              v-bind="lastNameAttrs"
              placeholder="Ej: López"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Email"
            :validate-status="errors.email ? 'error' : ''"
            :help="errors.email ?? ''"
          >
            <a-input
              v-model:value="email"
              v-bind="emailAttrs"
              placeholder="correo@ejemplo.com"
              disabled
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :sm="12">
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
        </a-col>
      </a-row>
    </FormSection>

    <FormSection title="Contactos de acceso" subtitle="Emails y teléfonos de contacto.">
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <div class="form-actions">
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
.form-actions {
  display: flex;
  gap: 10px;
  padding-top: 20px;
}
</style>
