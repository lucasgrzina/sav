<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { createSupportMessageSchema } from '../../validators/support-message.validator'
import type { CreateSupportMessagePayload } from '../../types/support-message.types'
import BaseRichTextEditor from '@/components/atoms/inputs/BaseRichTextEditor.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

const props = withDefaults(
  defineProps<{
    loading?: boolean
    fieldErrors?: Record<string, string> | null
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  submit: [values: CreateSupportMessagePayload]
}>()

const { errors, defineField, handleSubmit, setErrors } = useForm({
  validationSchema: toTypedSchema(createSupportMessageSchema),
})

const [subject, subjectAttrs] = defineField('subject')
const [body, bodyAttrs] = defineField('body')
const [priority, priorityAttrs] = defineField('priority')
const [category, categoryAttrs] = defineField('category')

const onSubmit = handleSubmit((values) => emit('submit', values))

watch(() => props.fieldErrors, (errs) => setErrors(errs ?? {}))
</script>

<template>
  <a-form layout="vertical" @submit.prevent="onSubmit">
    <a-form-item
      label="Asunto"
      :validate-status="errors.subject ? 'error' : ''"
      :help="errors.subject ?? ''"
    >
      <a-input v-model:value="subject" v-bind="subjectAttrs" placeholder="Describí brevemente el problema" />
    </a-form-item>

    <a-row :gutter="12">
      <a-col :xs="24" :sm="12">
        <a-form-item
          label="Categoría"
          :validate-status="errors.category ? 'error' : ''"
          :help="errors.category ?? ''"
        >
          <a-select v-model:value="category" v-bind="categoryAttrs" placeholder="Seleccioná una categoría" style="width: 100%">
            <a-select-option value="bug">Error / Bug</a-select-option>
            <a-select-option value="question">Consulta</a-select-option>
            <a-select-option value="feature_request">Solicitud de mejora</a-select-option>
            <a-select-option value="other">Otro</a-select-option>
          </a-select>
        </a-form-item>
      </a-col>
      <a-col :xs="24" :sm="12">
        <a-form-item
          label="Prioridad"
          :validate-status="errors.priority ? 'error' : ''"
          :help="errors.priority ?? ''"
        >
          <a-select v-model:value="priority" v-bind="priorityAttrs" placeholder="Seleccioná la prioridad" style="width: 100%">
            <a-select-option value="low">Baja</a-select-option>
            <a-select-option value="medium">Media</a-select-option>
            <a-select-option value="high">Alta</a-select-option>
          </a-select>
        </a-form-item>
      </a-col>
    </a-row>

    <a-form-item
      label="Mensaje"
      :validate-status="errors.body ? 'error' : ''"
      :help="errors.body ?? ''"
    >
      <BaseRichTextEditor
        :model-value="body ?? ''"
        :has-error="!!errors.body"
        placeholder="Describí el problema con el mayor detalle posible"
        @update:model-value="body = $event"
        @blur="bodyAttrs.onBlur"
      />
    </a-form-item>

    <a-form-item style="margin-bottom: 0; text-align: right">
      <a-space>
        <slot name="cancel" />
        <BaseButton variant="primary" html-type="submit" :loading="loading">Enviar mensaje</BaseButton>
      </a-space>
    </a-form-item>
  </a-form>
</template>
