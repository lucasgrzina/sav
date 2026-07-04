<script setup lang="ts">
import { watch } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ActivityMonthMatrix from './ActivityMonthMatrix.vue'
import { healthPlanTemplateSchema } from '../validators/health-plan-template.validator'
import { useCreateHealthPlanTemplate, useUpdateHealthPlanTemplate } from '../composables/useHealthPlanTemplateMutations'
import { listAllHealthActivitiesApi } from '../api/health-activities.api'
import { listAllHealthPlanCategoriesApi } from '../api/health-plan-categories.api'
import type { HealthPlanTemplate, ActivityAssignment } from '../types/health.types'
import type { HealthPlanTemplateFormValues } from '../validators/health-plan-template.validator'

const props = defineProps<{
  mode: 'create' | 'edit'
  template?: HealthPlanTemplate | null
}>()

const isOpen = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()

const { data: allActivities, isLoading: activitiesLoading } = useQuery({
  queryKey: ['admin-health-activities', { per_page: 200 }],
  queryFn: () => listAllHealthActivitiesApi(),
  staleTime: 1000 * 60 * 5,
})

const { data: allCategories, isLoading: categoriesLoading } = useQuery({
  queryKey: ['admin-health-plan-categories-all'],
  queryFn: () => listAllHealthPlanCategoriesApi(),
  staleTime: 1000 * 60 * 5,
})

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm<HealthPlanTemplateFormValues>({
  validationSchema: toTypedSchema(healthPlanTemplateSchema),
  initialValues: {
    name: '',
    health_plan_category_guid: '',
    activities: [],
  },
})

const [name, nameAttrs] = defineField('name')
const [health_plan_category_guid, categoryAttrs] = defineField('health_plan_category_guid')
const [activities] = defineField('activities')

const {
  mutate: mutateCreate,
  isPending: isCreating,
  fieldErrors: createFieldErrors,
  generalError: createGeneralError,
  resetErrors: resetCreateErrors,
} = useCreateHealthPlanTemplate()

const {
  mutate: mutateUpdate,
  isPending: isUpdating,
  fieldErrors: updateFieldErrors,
  generalError: updateGeneralError,
  resetErrors: resetUpdateErrors,
} = useUpdateHealthPlanTemplate()

const isFormPending = props.mode === 'create' ? isCreating : isUpdating
const fieldErrors = props.mode === 'create' ? createFieldErrors : updateFieldErrors
const generalError = props.mode === 'create' ? createGeneralError : updateGeneralError

watch(fieldErrors, (errs) => setErrors(errs ?? {}))

watch(isOpen, (open) => {
  if (open) {
    if (props.mode === 'create') {
      resetForm({ values: { name: '', health_plan_category_guid: '', activities: [] } })
      resetCreateErrors()
    } else if (props.mode === 'edit') {
      resetUpdateErrors()
    }
  }
})

watch(
  () => props.template,
  (val) => {
    if (val && props.mode === 'edit') {
      const mappedActivities: ActivityAssignment[] = val.activities.map((a) => ({
        health_activity_guid: a.guid,
        months: a.months,
      }))
      resetForm({
        values: {
          name: val.name,
          health_plan_category_guid: val.category.guid,
          activities: mappedActivities,
        },
      })
    }
  },
  { immediate: true },
)

const onSubmit = handleSubmit((values: HealthPlanTemplateFormValues) => {
  const payload = {
    name: values.name,
    health_plan_category_guid: values.health_plan_category_guid,
    activities: values.activities,
  }

  if (props.mode === 'create') {
    mutateCreate(payload, {
      onSuccess: () => {
        isOpen.value = false
        emit('success')
      },
    })
  } else {
    if (!props.template) return
    mutateUpdate(
      { guid: props.template.guid, payload },
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
    :title="mode === 'create' ? 'Nueva Plantilla de Plan Sanitario' : 'Editar Plantilla de Plan Sanitario'"
    :width="720"
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
          placeholder="Ej: Plan Bovinos Estándar"
        />
      </a-form-item>

      <a-form-item
        label="Categoría"
        :validate-status="errors.health_plan_category_guid ? 'error' : ''"
        :help="errors.health_plan_category_guid ?? ''"
      >
        <a-select
          v-model:value="health_plan_category_guid"
          v-bind="categoryAttrs"
          placeholder="Seleccioná una categoría"
          :loading="categoriesLoading"
          style="width: 100%"
        >
          <a-select-option
            v-for="cat in allCategories ?? []"
            :key="cat.guid"
            :value="cat.guid"
          >
            {{ cat.name }}
          </a-select-option>
        </a-select>
      </a-form-item>

      <a-form-item label="Actividades por mes">
        <a-spin v-if="activitiesLoading" />
        <ActivityMonthMatrix
          v-else
          v-model="activities"
          :available-activities="allActivities ?? []"
        />
      </a-form-item>
    </a-form>
    
    <template #footer>
      <a-space style="justify-content: flex-end; width: 100%">
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
        <BaseButton variant="primary" :loading="isFormPending" @click="onSubmit">
          {{ mode === 'create' ? 'Crear plantilla' : 'Guardar cambios' }}
        </BaseButton>
      </a-space>
    </template>
  </BaseDrawer>
</template>
