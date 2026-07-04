<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { healthActivitySchema } from '../validators/health-activity.validator'
import { useCreateHealthActivity, useUpdateHealthActivity } from '../composables/useHealthActivityMutations'
import type { HealthActivity } from '../types/health.types'
import type { HealthActivityFormValues } from '../validators/health-activity.validator'

const props = defineProps<{
  mode: 'create' | 'edit'
  activity?: HealthActivity | null
}>()

const isOpen = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm<HealthActivityFormValues>({
  validationSchema: toTypedSchema(healthActivitySchema),
})

const [name, nameAttrs] = defineField('name')
const [description, descriptionAttrs] = defineField('description')

const {
  mutate: mutateCreate,
  isPending: isCreating,
  fieldErrors: createFieldErrors,
  generalError: createGeneralError,
  resetErrors: resetCreateErrors,
} = useCreateHealthActivity()

const {
  mutate: mutateUpdate,
  isPending: isUpdating,
  fieldErrors: updateFieldErrors,
  generalError: updateGeneralError,
  resetErrors: resetUpdateErrors,
} = useUpdateHealthActivity()

const isPending = props.mode === 'create' ? isCreating : isUpdating
const fieldErrors = props.mode === 'create' ? createFieldErrors : updateFieldErrors
const generalError = props.mode === 'create' ? createGeneralError : updateGeneralError

watch(fieldErrors, (errs) => setErrors(errs ?? {}))

watch(isOpen, (open) => {
  if (open) {
    if (props.mode === 'create') {
      resetForm()
      resetCreateErrors()
    } else if (props.mode === 'edit') {
      resetUpdateErrors()
    }
  }
})

watch(
  () => props.activity,
  (val) => {
    if (val && props.mode === 'edit') {
      resetForm({
        values: {
          name: val.name,
          description: val.description ?? undefined,
        },
      })
    }
  },
  { immediate: true },
)

const onSubmit = handleSubmit((values: HealthActivityFormValues) => {
  const payload = {
    name: values.name,
    description: values.description ?? null,
  }

  if (props.mode === 'create') {
    mutateCreate(payload, {
      onSuccess: () => {
        isOpen.value = false
        emit('success')
      },
    })
  } else {
    if (!props.activity) return
    mutateUpdate(
      { guid: props.activity.guid, payload },
      {
        onSuccess: () => {
          isOpen.value = false
          emit('success')
        },
      },
    )
  }
})
</script>

<template>
  <BaseDrawer
    v-model="isOpen"
    :title="mode === 'create' ? 'Nueva Actividad Sanitaria' : 'Editar Actividad Sanitaria'"
    :width="480"
  >
    <a-form layout="vertical" @submit.prevent="onSubmit">
      <a-alert
        v-if="generalError"
        :message="generalError"
        type="error"
        show-icon
        style="margin-bottom: 16px"
      />

      <a-form-item
        label="Nombre"
        :validate-status="errors.name ? 'error' : ''"
        :help="errors.name ?? ''"
      >
        <a-input
          v-model:value="name"
          v-bind="nameAttrs"
          placeholder="Ej: Vacuna Aftosa"
        />
      </a-form-item>

      <a-form-item
        label="Descripción"
        :validate-status="errors.description ? 'error' : ''"
        :help="errors.description ?? ''"
      >
        <a-textarea
          v-model:value="description"
          v-bind="descriptionAttrs"
          placeholder="Descripción opcional de la actividad"
          :rows="4"
        />
      </a-form-item>
    </a-form>

    <template #footer>
      <a-space style="justify-content: flex-end; width: 100%">
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
        <BaseButton variant="primary" :loading="isPending" @click="onSubmit">
          {{ mode === 'create' ? 'Crear actividad' : 'Guardar cambios' }}
        </BaseButton>
      </a-space>
    </template>
  </BaseDrawer>
</template>
