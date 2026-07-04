<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { techniqueSchema } from '../validators/technique.validator'
import type { TechniqueFormValues } from '../validators/technique.validator'
import SubTechniqueRepeater from './SubTechniqueRepeater.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { Technique } from '../types/technique.types'

const props = withDefaults(
  defineProps<{
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    initialValues?: Technique | null
  }>(),
  { loading: false, initialValues: null },
)

const emit = defineEmits<{
  submit: [values: TechniqueFormValues]
  cancel: []
}>()

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm({
  validationSchema: toTypedSchema(techniqueSchema),
  initialValues: props.initialValues
    ? {
        name: props.initialValues.name,
        type: props.initialValues.type,
        target_date_name: props.initialValues.target_date_name ?? '',
        protocols_name: props.initialValues.protocols_name ?? '',
        children: props.initialValues.children ?? [],
      }
    : undefined,
})

const [name, nameAttrs] = defineField('name')
const [type, typeAttrs] = defineField('type')
const [targetDateName, targetDateNameAttrs] = defineField('target_date_name')
const [protocolsName, protocolsNameAttrs] = defineField('protocols_name')
const [children, childrenAttrs] = defineField('children')

const onSubmit = handleSubmit((values) => emit('submit', values))

watch(() => props.fieldErrors, (errs) => setErrors(errs ?? {}))

watch(
  () => props.initialValues,
  (val) => {
    if (val) {
      resetForm({
        values: {
          name: val.name,
          type: val.type,
          target_date_name: val.target_date_name ?? '',
          protocols_name: val.protocols_name ?? '',
          children: val.children ?? [],
        },
      })
    }
  },
)
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <a-form-item
      label="Nombre"
      :validate-status="errors.name ? 'error' : ''"
      :help="errors.name ?? ''"
      required
    >
      <a-input
        v-model:value="name"
        v-bind="nameAttrs"
        placeholder="Ej: Inseminación Artificial"
      />
    </a-form-item>

    <a-form-item
      label="Tipo"
      :validate-status="errors.type ? 'error' : ''"
      :help="errors.type ?? ''"
      required
    >
      <a-select
        v-model:value="type"
        v-bind="typeAttrs"
        placeholder="Seleccioná el tipo"
        style="width: 100%"
      >
        <a-select-option value="technique">Técnica de reproducción</a-select-option>
        <a-select-option value="vaccine">Vacuna</a-select-option>
      </a-select>
    </a-form-item>

    <a-form-item
      label="Label fecha objetivo"
      :validate-status="errors.target_date_name ? 'error' : ''"
      :help="errors.target_date_name ?? ''"
    >
      <a-input
        v-model:value="targetDateName"
        v-bind="targetDateNameAttrs"
        placeholder="Ej: Fecha de servicio"
      />
    </a-form-item>

    <a-form-item
      label="Label selector de protocolos"
      :validate-status="errors.protocols_name ? 'error' : ''"
      :help="errors.protocols_name ?? ''"
    >
      <a-input
        v-model:value="protocolsName"
        v-bind="protocolsNameAttrs"
        placeholder="Ej: Seleccionar protocolo de sincronización"
      />
    </a-form-item>

    <a-form-item label="Sub-técnicas">
      <SubTechniqueRepeater v-model="children" />
    </a-form-item>

    <a-form-item style="margin-bottom: 0; text-align: right">
      <a-space>
        <BaseButton variant="secondary" html-type="button" @click="emit('cancel')">
          Cancelar
        </BaseButton>
        <BaseButton variant="primary" html-type="submit" :loading="loading">
          {{ initialValues ? 'Guardar cambios' : 'Crear técnica' }}
        </BaseButton>
      </a-space>
    </a-form-item>
  </a-form>
</template>
