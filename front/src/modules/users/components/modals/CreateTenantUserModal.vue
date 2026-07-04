<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, useFieldArray } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import TenantProfileRow from '../forms/TenantProfileRow.vue'
import { useCreateTenantUser } from '../../composables/useCreateTenantUser'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import { tenantUserCreateSchema } from '@/modules/users/validators/user.validator'

const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useCreateTenantUser()

const rolesStore = useRolesCacheStore()
// Disparar la carga si aún no se hizo (el store garantiza que no se re-fetcha)
rolesStore.fetchTenantRoles()

const tenantRoles = computed(() => rolesStore.tenantRoles)
const isLoadingRoles = computed(() => rolesStore.loading)

const { errors, defineField, handleSubmit, resetForm, setErrors } = useForm({
  validationSchema: toTypedSchema(tenantUserCreateSchema),
  initialValues: {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
    profiles: [{ role_guid: '', vet_guid: undefined, client_guid: undefined }],
  },
})

const [first_name, firstNameAttrs] = defineField('first_name')
const [last_name, lastNameAttrs] = defineField('last_name')
const [email, emailAttrs] = defineField('email')
const [password, passwordAttrs] = defineField('password')
const [password_confirmation, passwordConfirmationAttrs] = defineField('password_confirmation')

const { fields: profileFields, push: addProfile, remove: removeProfile } = useFieldArray('profiles')

watch(isOpen, (open) => {
  if (open) {
    resetErrors()
    resetForm()
  }
})

watch(
  () => fieldErrors.value,
  (errs) => {
    if (errs) setErrors(errs)
  },
)

function setProfileRole(index: number, guid: string) {
  profileFields.value[index].value.role_guid = guid
  profileFields.value[index].value.vet_guid = undefined
  profileFields.value[index].value.client_guid = undefined
}

function setProfileVet(index: number, guid: string) {
  profileFields.value[index].value.vet_guid = guid || undefined
  profileFields.value[index].value.client_guid = undefined
}

function setProfileClient(index: number, guid: string) {
  profileFields.value[index].value.client_guid = guid || undefined
  profileFields.value[index].value.vet_guid = undefined
}

const onSubmit = handleSubmit((values) => {
  mutate(
    {
      first_name: values.first_name,
      last_name: values.last_name,
      email: values.email,
      password: values.password,
      password_confirmation: values.password_confirmation,
      profiles: values.profiles.map((p) => ({
        role_guid: p.role_guid,
        vet_guid: p.vet_guid || undefined,
        client_guid: p.client_guid || undefined,
      })),
    },
    {
      onSuccess: () => {
        isOpen.value = false
      },
    },
  )
})
</script>

<template>
  <BaseModal v-model="isOpen" title="Nuevo usuario de tenant" :width="700">
    <a-alert
      v-if="generalError && !fieldErrors"
      :message="generalError"
      type="error"
      show-icon
      style="margin-bottom: 16px"
    />

    <a-form layout="vertical" @submit.prevent="onSubmit">
      <a-row :gutter="12">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Nombre"
            :validate-status="errors.first_name ? 'error' : ''"
            :help="errors.first_name ?? ''"
          >
            <a-input
              v-model:value="first_name"
              v-bind="firstNameAttrs"
              placeholder="Juan"
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
              placeholder="García"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item
        label="Email"
        :validate-status="errors.email ? 'error' : ''"
        :help="errors.email ?? ''"
      >
        <a-input
          v-model:value="email"
          v-bind="emailAttrs"
          placeholder="juan@ejemplo.com"
          autocomplete="off"
        />
      </a-form-item>

      <a-row :gutter="12">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Contraseña"
            :validate-status="errors.password ? 'error' : ''"
            :help="errors.password ?? ''"
          >
            <a-input-password
              v-model:value="password"
              v-bind="passwordAttrs"
              autocomplete="new-password"
              placeholder="Mínimo 8 caracteres"
            />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Confirmar contraseña"
            :validate-status="errors.password_confirmation ? 'error' : ''"
            :help="errors.password_confirmation ?? ''"
          >
            <a-input-password
              v-model:value="password_confirmation"
              v-bind="passwordConfirmationAttrs"
              autocomplete="new-password"
              placeholder="Repetí la contraseña"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-divider>Perfiles de acceso</a-divider>

      <a-alert
        v-if="errors['profiles']"
        :message="errors['profiles']"
        type="error"
        show-icon
        style="margin-bottom: 12px"
      />

      <TenantProfileRow
        v-for="(field, index) in profileFields"
        :key="field.key"
        :index="index"
        :roles="tenantRoles"
        :loading-roles="isLoadingRoles"
        :field-errors="fieldErrors"
        @remove="removeProfile(index)"
        @update:role="(guid) => setProfileRole(index, guid)"
        @update:vet="(guid) => setProfileVet(index, guid)"
        @update:client="(guid) => setProfileClient(index, guid)"
      />

      <!-- a-button type="dashed" no mapeado directamente a BaseButton, se conserva para mantener estilo visual dashed -->
      <a-button
        type="dashed"
        block
        style="margin-bottom: 16px"
        @click="addProfile({ role_guid: '', vet_guid: undefined, client_guid: undefined })"
      >
        <template #icon><PlusOutlined /></template>
        Agregar perfil
      </a-button>

      <a-form-item style="margin-top: 8px; margin-bottom: 0; text-align: right">
        <a-space>
          <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
          <BaseButton variant="primary" html-type="submit" :loading="isPending">
            Crear usuario
          </BaseButton>
        </a-space>
      </a-form-item>
    </a-form>
  </BaseModal>
</template>
