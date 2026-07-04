<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { contactSchema } from '../../validators/client.validator'
import { useCreateContact } from '../../composables/useCreateContact'
import { useUpdateContact } from '../../composables/useUpdateContact'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { ContactItem } from '../../types/client.types'
import type { ContactForm } from '../../validators/client.validator'

const CONTACT_TYPE_OPTIONS = [
  { value: 'phone',    label: 'Teléfono' },
  { value: 'email',    label: 'Email' },
  { value: 'whatsapp', label: 'WhatsApp' },
]

const props = defineProps<{
  clientGuid: string
  mode: 'create' | 'edit'
  initial?: Partial<ContactItem>
  contactGuid?: string
}>()

const emit = defineEmits<{
  success: []
}>()

const isOpen = defineModel<boolean>({ default: false })

const { errors, defineField, handleSubmit, setErrors, setValues, resetForm } = useForm<ContactForm>({
  validationSchema: toTypedSchema(contactSchema),
})

const [type, typeAttrs]                 = defineField('type')
const [value, valueAttrs]               = defineField('value')
const [label, labelAttrs]               = defineField('label')
const [is_primary, isPrimaryAttrs]      = defineField('is_primary')
const [use_for_alerts, useAlertsAttrs]  = defineField('use_for_alerts')

const createMutation = useCreateContact()
const updateMutation = useUpdateContact()

const isPending   = props.mode === 'create' ? createMutation.isPending : updateMutation.isPending
const fieldErrors = props.mode === 'create' ? createMutation.fieldErrors : updateMutation.fieldErrors

watch(() => props.initial, (vals) => {
  if (props.mode === 'edit' && vals) {
    setValues({
      type:           vals.type ?? '',
      value:          vals.value ?? '',
      label:          vals.label ?? null,
      is_primary:     vals.is_primary ?? false,
      use_for_alerts: vals.use_for_alerts ?? false,
    })
  }
}, { immediate: true, deep: true })

watch(fieldErrors, (errs) => {
  setErrors(errs ?? {})
})

const onSubmit = handleSubmit(async (values) => {
  if (props.mode === 'create') {
    await createMutation.mutateAsync(
      { clientGuid: props.clientGuid, payload: values },
      {
        onSuccess: () => {
          resetForm()
          isOpen.value = false
          emit('success')
        },
      },
    )
  } else if (props.contactGuid) {
    await updateMutation.mutateAsync(
      { clientGuid: props.clientGuid, contactGuid: props.contactGuid, payload: values },
      {
        onSuccess: () => {
          isOpen.value = false
          emit('success')
        },
      },
    )
  }
})

function handleCancel(): void {
  isOpen.value = false
  resetForm()
}
</script>

<template>
  <BaseModal
    v-model="isOpen"
    :title="mode === 'create' ? 'Nuevo contacto' : 'Editar contacto'"
    :width="480"
    @cancel="handleCancel"
  >
    <form @submit.prevent="onSubmit">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="12">
          <a-form-item
            label="Tipo"
            :validate-status="errors.type ? 'error' : ''"
            :help="errors.type ?? ''"
          >
            <a-select
              v-model:value="type"
              v-bind="typeAttrs"
              :options="CONTACT_TYPE_OPTIONS"
              placeholder="Seleccioná el tipo"
              style="width: 100%"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="12">
          <a-form-item
            label="Valor"
            :validate-status="errors.value ? 'error' : ''"
            :help="errors.value ?? ''"
          >
            <a-input
              v-model:value="value"
              v-bind="valueAttrs"
              placeholder="Ej: +54 11 1234-5678"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item
        label="Etiqueta"
        :validate-status="errors.label ? 'error' : ''"
        :help="errors.label ?? ''"
      >
        <a-input
          v-model:value="label"
          v-bind="labelAttrs"
          placeholder="Ej: Contacto principal"
        />
      </a-form-item>

      <div class="cf-checks">
        <a-checkbox
          v-model:checked="is_primary"
          v-bind="isPrimaryAttrs"
        >
          Contacto principal
        </a-checkbox>

        <a-checkbox
          v-model:checked="use_for_alerts"
          v-bind="useAlertsAttrs"
        >
          Usar para alertas
        </a-checkbox>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="secondary" @click="handleCancel">Cancelar</BaseButton>
      <BaseButton
        variant="primary"
        :loading="isPending.value"
        @click="onSubmit"
      >
        {{ mode === 'create' ? 'Crear contacto' : 'Guardar cambios' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>

<style scoped>
.cf-checks {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 4px;
}
</style>
