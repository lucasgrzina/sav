<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { healthPlanCategorySchema } from '../validators/health-plan-category.validator'
import { useCreateHealthPlanCategory, useUpdateHealthPlanCategory } from '../composables/useHealthPlanCategoryMutations'
import type { HealthPlanCategory } from '../types/health.types'
import type { HealthPlanCategoryFormValues } from '../validators/health-plan-category.validator'

const props = defineProps<{
  mode: 'create' | 'edit'
  category?: HealthPlanCategory | null
}>()

const isOpen = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm<HealthPlanCategoryFormValues>({
  validationSchema: toTypedSchema(healthPlanCategorySchema),
})

const [name, nameAttrs] = defineField('name')
const [description, descriptionAttrs] = defineField('description')

const {
  mutate: mutateCreate,
  isPending: isCreating,
  fieldErrors: createFieldErrors,
  generalError: createGeneralError,
  resetErrors: resetCreateErrors,
} = useCreateHealthPlanCategory()

const {
  mutate: mutateUpdate,
  isPending: isUpdating,
  fieldErrors: updateFieldErrors,
  generalError: updateGeneralError,
  resetErrors: resetUpdateErrors,
} = useUpdateHealthPlanCategory()

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
  () => props.category,
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

const onSubmit = handleSubmit((values: HealthPlanCategoryFormValues) => {
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
    if (!props.category) return
    mutateUpdate(
      { guid: props.category.guid, payload },
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
    :title="mode === 'create' ? 'Nueva Categoría de Plan' : 'Editar Categoría de Plan'"
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
          placeholder="Ej: Bovinos"
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
          placeholder="Descripción opcional de la categoría"
          :rows="4"
        />
      </a-form-item>
    </a-form>

    <template #footer>
      <a-space style="justify-content: flex-end; width: 100%">
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
        <BaseButton variant="primary" :loading="isPending" @click="onSubmit">
          {{ mode === 'create' ? 'Crear categoría' : 'Guardar cambios' }}
        </BaseButton>
      </a-space>
    </template>
  </BaseDrawer>
</template>
