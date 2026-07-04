<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { vetCreateSchema, vetUpdateSchema, vetTenantUpdateSchema } from '../../validators/vet.validator'
import { useCountries } from '../../composables/useCountries'
import { useDocumentTypes } from '../../composables/useDocumentTypes'
import type { VetCreateForm, VetUpdateForm } from '../../validators/vet.validator'
import type { VetItem, ContactFormItem, VetUpdatePayload } from '../../types/vet.types'

const props = withDefaults(
  defineProps<{
    origin: 'admin' | 'tenant'
    mode?: 'create' | 'edit'
    initialValues?: Partial<VetItem>
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    cancelTo?: string
  }>(),
  { mode: 'edit', loading: false },
)

const emit = defineEmits<{
  submit: [values: VetCreateForm | VetUpdateForm | VetUpdatePayload]
}>()

const schema = computed(() => {
  if (props.origin === 'tenant') return vetTenantUpdateSchema
  return props.mode === 'create' ? vetCreateSchema : vetUpdateSchema
})

const { errors, defineField, handleSubmit, setErrors, setValues, resetForm } = useForm({
  validationSchema: computed(() => toTypedSchema(schema.value)),
})

const isPopulating = ref(false)

const [name, nameAttrs]                              = defineField('name')
const [country_guid, countryGuidAttrs]               = defineField('country_guid')
const [document_type_guid, documentTypeGuidAttrs]    = defineField('document_type_guid')
const [tax_id, taxIdAttrs]                           = defineField('tax_id')
const [registration_number, registrationNumberAttrs] = defineField('registration_number')
const [pdf_title, pdfTitleAttrs]                     = defineField('pdf_title')
const [pdf_subtitle, pdfSubtitleAttrs]               = defineField('pdf_subtitle')

const localContacts = ref<ContactFormItem[]>([])

const { data: countriesData, isLoading: isLoadingCountries } = useCountries()
const countryOptions = computed(() =>
  (countriesData.value ?? []).map((c) => ({ value: c.guid, label: c.name })),
)

const { data: documentTypesData, isLoading: isLoadingDocTypes } = useDocumentTypes(
  computed(() => (country_guid.value as string) ?? ''),
)
const documentTypeOptions = computed(() =>
  (documentTypesData.value ?? []).map((dt) => ({ value: dt.guid, label: dt.name })),
)

watch(country_guid, () => {
  if (props.origin === 'admin' && !isPopulating.value) {
    document_type_guid.value = ''
  }
})

watch(
  () => props.initialValues,
  (vals) => {
    if (props.mode !== 'edit' || !vals) return
    isPopulating.value = true
    if (props.origin === 'admin') {
      setValues({
        name:                vals.name ?? '',
        country_guid:        vals.country?.guid ?? '',
        document_type_guid:  vals.document_type?.guid ?? '',
        tax_id:              vals.tax_id ?? '',
        registration_number: vals.registration_number ?? null,
        pdf_title:           vals.pdf_title ?? null,
        pdf_subtitle:        vals.pdf_subtitle ?? null,
      })
    } else {
      setValues({
        name:        vals.name ?? '',
        pdf_title:   vals.pdf_title ?? null,
        pdf_subtitle: vals.pdf_subtitle ?? null,
      })
    }
    localContacts.value = (vals.contacts ?? []).map((c) => ({
      type:           c.type,
      value:          c.value,
      label:          c.label,
      is_primary:     c.is_primary,
      use_for_alerts: c.use_for_alerts,
    }))
    nextTick(() => { isPopulating.value = false })
  },
  { immediate: true, deep: true },
)

watch(() => props.fieldErrors, (errs) => {
  setErrors(errs ?? {})
})

const resolvedCancelTo = computed(() => {
  if (props.cancelTo) return props.cancelTo
  if (props.mode === 'create') return '/admin/vets'
  return `/admin/vets/${props.initialValues?.guid ?? ''}`
})

const onSubmit = handleSubmit((values) => {
  const contacts = localContacts.value.map((c) => ({ ...c, label: c.label || null }))
  if (props.origin === 'tenant') {
    const { name, pdf_title, pdf_subtitle } = values as { name: string; pdf_title: string | null; pdf_subtitle: string | null }
    emit('submit', { name, pdf_title, pdf_subtitle, contacts })
    return
  }
  if (props.mode === 'create') {
    emit('submit', { ...(values as VetCreateForm), contacts })
  } else {
    const { name, document_type_guid, tax_id, registration_number, pdf_title, pdf_subtitle } = values as VetUpdateForm
    emit('submit', { name, document_type_guid, tax_id, registration_number, pdf_title, pdf_subtitle, contacts })
  }
})

defineExpose({ resetForm })
</script>

<template>
  <a-form class="form" layout="vertical" @submit.prevent="onSubmit">
    <FormSection title="Información de la veterinaria">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Nombre"
            :validate-status="errors.name ? 'error' : ''"
            :help="errors.name ?? ''"
          >
            <a-input
              v-model:value="name"
              v-bind="nameAttrs"
              placeholder="Ej: Veterinaria San Martín"
            />
          </a-form-item>
        </a-col>

        <template v-if="origin === 'admin'">
          <a-col :xs="24" :md="12">
            <a-form-item
              label="País"
              :validate-status="errors.country_guid ? 'error' : ''"
              :help="errors.country_guid ?? ''"
            >
              <a-select
                v-model:value="country_guid"
                v-bind="countryGuidAttrs"
                :loading="isLoadingCountries"
                :options="countryOptions"
                placeholder="Seleccioná un país"
                allow-clear
                show-search
                option-filter-prop="label"
                style="width: 100%"
                :disabled="mode === 'edit'"
              />
            </a-form-item>
          </a-col>
        </template>
      </a-row>

      <template v-if="origin === 'admin'">
        <a-row :gutter="[16, 0]">
          <a-col :xs="24" :md="12">
            <a-form-item
              label="Tipo de documento"
              :validate-status="errors.document_type_guid ? 'error' : ''"
              :help="errors.document_type_guid ?? ''"
            >
              <a-select
                v-model:value="document_type_guid"
                v-bind="documentTypeGuidAttrs"
                :loading="isLoadingDocTypes"
                :options="documentTypeOptions"
                placeholder="Seleccioná el tipo de doc."
                allow-clear
                style="width: 100%"
                :disabled="!country_guid"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item
              label="CUIT / Identificador fiscal"
              :validate-status="errors.tax_id ? 'error' : ''"
              :help="errors.tax_id ?? ''"
            >
              <a-input
                v-model:value="tax_id"
                v-bind="taxIdAttrs"
                placeholder="Ej: 30-12345678-9"
              />
            </a-form-item>
          </a-col>
        </a-row>

        <a-form-item
          label="Número de matrícula / registro"
          :validate-status="errors.registration_number ? 'error' : ''"
          :help="errors.registration_number ?? ''"
        >
          <a-input
            v-model:value="registration_number"
            v-bind="registrationNumberAttrs"
            placeholder="Opcional"
          />
        </a-form-item>
      </template>
    </FormSection>

    <FormSection
      title="Contactos de la veterinaria"
      subtitle="Emails y teléfonos de contacto. El contacto principal es el que se muestra por defecto."
    >
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <FormSection title="Personalización de documentos" subtitle="Datos opcionales para encabezados de PDF generados.">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="12">
          <a-form-item
            label="Título del PDF"
            :validate-status="errors.pdf_title ? 'error' : ''"
            :help="errors.pdf_title ?? ''"
          >
            <a-input
              v-model:value="pdf_title"
              v-bind="pdfTitleAttrs"
              placeholder="Ej: Certificado veterinario"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="12">
          <a-form-item
            label="Subtítulo del PDF"
            :validate-status="errors.pdf_subtitle ? 'error' : ''"
            :help="errors.pdf_subtitle ?? ''"
          >
            <a-input
              v-model:value="pdf_subtitle"
              v-bind="pdfSubtitleAttrs"
              placeholder="Ej: Emitido bajo resolución..."
            />
          </a-form-item>
        </a-col>
      </a-row>
    </FormSection>

    <FormFooter
      :loading="loading"
      :cancel-to="resolvedCancelTo"
      :save-label="mode === 'create' ? 'Crear veterinaria' : 'Guardar cambios'"
    />
  </a-form>
</template>

<style scoped>
.form { display: flex; flex-direction: column; gap: 20px; }
</style>
