<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { changePasswordSchema } from '@/modules/users/validators/user.validator'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { ChangePasswordPayload } from '../../types/user.types'

const props = withDefaults(
  defineProps<{ loading?: boolean; fieldErrors?: Record<string, string> | null }>(),
  { loading: false },
)

const emit = defineEmits<{ submit: [values: ChangePasswordPayload] }>()

const { errors, handleSubmit, defineField, setErrors } = useForm({
  validationSchema: toTypedSchema(changePasswordSchema),
})

const [password, passwordAttrs] = defineField('password')
const [password_confirmation, passwordConfirmationAttrs] = defineField('password_confirmation')

const onSubmit = handleSubmit((values) => {
  emit('submit', values as ChangePasswordPayload)
})

watch(() => props.fieldErrors, (errors) => {
  setErrors(errors ?? {})
})
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <a-form-item
      label="Nueva contraseña"
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

    <a-form-item style="margin-bottom: 0; text-align: right">
      <a-space>
        <slot name="cancel" />
        <BaseButton variant="primary" html-type="submit" :loading="loading">
          Cambiar contraseña
        </BaseButton>
      </a-space>
    </a-form-item>
  </a-form>
</template>
