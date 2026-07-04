<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { userCreateSchema, userUpdateSchema } from '@/modules/users/validators/user.validator'
import { useRoles } from '@/modules/roles/composables/useRoles'
import { getRoleLabel } from '@/core/utils/roles'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { UserCreatePayload, UserUpdatePayload } from '../../types/user.types'
import type { RoleType } from '@/modules/roles/types/role.types'

const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit'
    initialValues?: Partial<UserUpdatePayload>
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    roleType?: RoleType
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  submit: [values: UserCreatePayload | UserUpdatePayload]
}>()

const { errors, defineField, handleSubmit, setErrors, setValues } = useForm<UserCreatePayload>({
  validationSchema: toTypedSchema(props.mode === 'create' ? userCreateSchema : userUpdateSchema),
})

const [first_name, firstNameAttrs] = defineField('first_name')
const [last_name, lastNameAttrs] = defineField('last_name')
const [email, emailAttrs] = defineField('email')
const [password, passwordAttrs] = defineField('password')
const [password_confirmation, passwordConfirmationAttrs] = defineField('password_confirmation')
const [role_guids, roleGuidsAttrs] = defineField('role_guids')

const rolesFilters = computed(() => ({
  per_page: 100,
  ...(props.roleType ? { type: props.roleType } : {}),
}))
const { data: rolesData, isLoading: isLoadingRoles } = useRoles(rolesFilters)
const availableRoles = computed(() =>
  (rolesData.value?.data ?? []).map((r) => ({ value: r.guid, label: getRoleLabel(r.name) })),
)

watch(
  () => props.initialValues,
  (vals) => {
    if (props.mode === 'edit' && vals) {
      setValues(vals as UserCreatePayload)
    }
  },
  { immediate: true, deep: true },
)

const onSubmit = handleSubmit((values) => {
  if (props.mode === 'create') {
    emit('submit', values)
  } else {
    const { first_name, last_name, email, role_guids } = values
    emit('submit', { first_name, last_name, email, role_guids })
  }
})

watch(() => props.fieldErrors, (errors) => {
  setErrors(errors ?? {})
})
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <a-row :gutter="12">
      <a-col :xs="24" :sm="12">
        <a-form-item
          label="Nombre"
          :validate-status="errors.first_name ? 'error' : ''"
          :help="errors.first_name ?? ''"
        >
          <a-input v-model:value="first_name" v-bind="firstNameAttrs" placeholder="Juan" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :sm="12">
        <a-form-item
          label="Apellido"
          :validate-status="errors.last_name ? 'error' : ''"
          :help="errors.last_name ?? ''"
        >
          <a-input v-model:value="last_name" v-bind="lastNameAttrs" placeholder="García" />
        </a-form-item>
      </a-col>
    </a-row>

    <a-form-item
      label="Email"
      :validate-status="errors.email ? 'error' : ''"
      :help="errors.email ?? ''"
    >
      <a-input v-model:value="email" v-bind="emailAttrs" placeholder="juan@ejemplo.com" autocomplete="off" />
    </a-form-item>

    <a-form-item
      v-if="roleType === 'platform'"
      label="Roles"
      :validate-status="errors.role_guids ? 'error' : ''"
      :help="errors.role_guids ?? ''"
    >
      <a-select
        v-model:value="role_guids"
        v-bind="roleGuidsAttrs"
        mode="multiple"
        :loading="isLoadingRoles"
        :options="availableRoles"
        placeholder="Seleccioná uno o más roles"
        allow-clear
        style="width: 100%"
      />
    </a-form-item>

    <template v-if="mode === 'create'">
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
    </template>

    <slot name="footer">
      <a-form-item style="margin-bottom: 0; text-align: right">
        <a-space>
          <slot name="cancel" />
          <BaseButton variant="primary" html-type="submit" :loading="loading">
            {{ mode === 'create' ? 'Crear usuario' : 'Guardar cambios' }}
          </BaseButton>
        </a-space>
      </a-form-item>
    </slot>
  </a-form>
</template>
