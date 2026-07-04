<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { tutorialSchema } from '../../validators/tutorial.validator'
import type { TutorialFormValues } from '../../validators/tutorial.validator'
import type { Tutorial } from '../../types/tutorial.types'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

const props = withDefaults(
  defineProps<{
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    initialValues?: Tutorial | null
  }>(),
  { loading: false, initialValues: null },
)

const emit = defineEmits<{
  submit: [values: TutorialFormValues]
}>()

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm({
  validationSchema: toTypedSchema(tutorialSchema),
  initialValues: props.initialValues
    ? {
        title: props.initialValues.title,
        description: props.initialValues.description,
        source: props.initialValues.source,
        code: props.initialValues.code,
        order: props.initialValues.order,
      }
    : undefined,
})

const [title, titleAttrs] = defineField('title')
const [description, descriptionAttrs] = defineField('description')
const [source, sourceAttrs] = defineField('source')
const [code, codeAttrs] = defineField('code')
const [order, orderAttrs] = defineField('order')

const onSubmit = handleSubmit((values) => emit('submit', values))

watch(() => props.fieldErrors, (errs) => setErrors(errs ?? {}))

watch(
  () => props.initialValues,
  (val) => {
    if (val) {
      resetForm({
        values: {
          title: val.title,
          description: val.description,
          source: val.source,
          code: val.code,
          order: val.order,
        },
      })
    }
  },
)
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <a-form-item
      label="Título"
      :validate-status="errors.title ? 'error' : ''"
      :help="errors.title ?? ''"
    >
      <a-input
        v-model:value="title"
        v-bind="titleAttrs"
        placeholder="Ej: Cómo registrar un animal"
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
        placeholder="Descripción breve del contenido del tutorial"
        :rows="3"
      />
    </a-form-item>

    <a-row :gutter="12">
      <a-col :xs="24" :sm="12">
        <a-form-item
          label="Fuente"
          :validate-status="errors.source ? 'error' : ''"
          :help="errors.source ?? ''"
        >
          <a-select
            v-model:value="source"
            v-bind="sourceAttrs"
            placeholder="Seleccioná la plataforma"
            style="width: 100%"
          >
            <a-select-option value="youtube">YouTube</a-select-option>
            <a-select-option value="vimeo">Vimeo</a-select-option>
          </a-select>
        </a-form-item>
      </a-col>
      <a-col :xs="24" :sm="12">
        <a-form-item
          label="Código del video"
          :validate-status="errors.code ? 'error' : ''"
          :help="errors.code ?? ''"
        >
          <a-input
            v-model:value="code"
            v-bind="codeAttrs"
            placeholder="Ej: dQw4w9WgXcQ"
          />
        </a-form-item>
      </a-col>
    </a-row>

    <a-form-item
      label="Orden"
      :validate-status="errors.order ? 'error' : ''"
      :help="errors.order ?? ''"
    >
      <a-input-number
        v-model:value="order"
        v-bind="orderAttrs"
        :min="0"
        :precision="0"
        style="width: 100%"
        placeholder="0"
      />
    </a-form-item>

    <a-form-item style="margin-bottom: 0; text-align: right">
      <a-space>
        <slot name="cancel" />
        <BaseButton variant="primary" html-type="submit" :loading="loading">
          {{ initialValues ? 'Guardar cambios' : 'Crear tutorial' }}
        </BaseButton>
      </a-space>
    </a-form-item>
  </a-form>
</template>
