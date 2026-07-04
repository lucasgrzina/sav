<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { clientCreateSchema, clientUpdateSchema } from '../../validators/client.validator'
import { useCountries } from '@/modules/vets/composables/useCountries'
import { useDocumentTypes } from '@/modules/vets/composables/useDocumentTypes'
import ContactsInput from '@/components/forms/ContactsInput.vue'
import type { ClientCreateForm, ClientUpdateForm } from '../../validators/client.validator'
import type { ClientItem } from '../../types/client.types'
import type { ContactFormItem } from '@/modules/vets/types/vet.types'

type ClientFormSubmit = (ClientCreateForm & { contacts: ContactFormItem[] }) | ClientUpdateForm

const props = withDefaults(
  defineProps<{
    mode: 'create' | 'edit'
    initialValues?: Partial<ClientItem>
    loading?: boolean
    fieldErrors?: Record<string, string> | null
  }>(),
  { loading: false },
)

const emit = defineEmits<{
  submit: [values: ClientFormSubmit]
}>()

const schema = computed(() =>
  props.mode === 'create' ? clientCreateSchema : clientUpdateSchema,
)

const { errors, defineField, handleSubmit, setErrors, setValues } = useForm<ClientCreateForm | ClientUpdateForm>({
  validationSchema: computed(() => toTypedSchema(schema.value)),
})

const isPopulating = ref(false)

const [name, nameAttrs]           = defineField('name')
const [country_guid, countryGuidAttrs] = defineField('country_guid' as keyof (ClientCreateForm | ClientUpdateForm))
const [document_type_guid, documentTypeGuidAttrs] = defineField('document_type_guid' as keyof (ClientCreateForm | ClientUpdateForm))
const [tax_id, taxIdAttrs]        = defineField('tax_id')
const [address, addressAttrs]     = defineField('address')
const [city, cityAttrs]           = defineField('city')
const [state, stateAttrs]         = defineField('state')
const [zip_code, zipCodeAttrs]    = defineField('zip_code')

const localContacts = ref<ContactFormItem[]>([])

const { data: countriesData, isLoading: isLoadingCountries } = useCountries()
const countryOptions = computed(() =>
  (countriesData.value ?? []).map((c) => ({ value: c.guid, label: c.name })),
)

const { data: documentTypesData, isLoading: isLoadingDocTypes } = useDocumentTypes(
  computed(() => (country_guid.value as string | undefined) ?? ''),
)
const documentTypeOptions = computed(() =>
  (documentTypesData.value ?? []).map((dt) => ({ value: dt.guid, label: dt.name })),
)

watch(country_guid, () => {
  if (props.mode === 'create' && !isPopulating.value) {
    document_type_guid.value = ''
  }
})

watch(
  () => props.initialValues,
  (vals) => {
    if (vals) {
      isPopulating.value = true
      if (props.mode === 'edit') {
        country_guid.value = vals.country?.guid ?? ''
        setValues({
          name:               vals.name ?? '',
          document_type_guid: vals.document_type?.guid ?? '',
          tax_id:             vals.tax_id ?? '',
          address:            vals.address ?? null,
          city:               vals.city ?? null,
          state:              vals.state ?? null,
          zip_code:           vals.zip_code ?? null,
        })
        localContacts.value = (vals.contacts ?? []).map((c) => ({
          guid: c.guid,
          type: c.type as ContactFormItem['type'],
          value: c.value,
          label: c.label,
          is_primary: c.is_primary,
          use_for_alerts: c.use_for_alerts,
        }))
      } else {
        if (vals.tax_id) {
          setValues({ tax_id: vals.tax_id } as Partial<ClientCreateForm>)
        }
      }
      nextTick(() => { isPopulating.value = false })
    }
  },
  { immediate: true, deep: true },
)

watch(() => props.fieldErrors, (errs) => {
  setErrors(errs ?? {})
})

const onSubmit = handleSubmit((values) => {
  const contacts = localContacts.value.map((c) => ({ ...c, label: c.label || null }))
  if (props.mode === 'create') {
    emit('submit', { ...(values as ClientCreateForm), contacts })
  } else {
    const { name, document_type_guid, tax_id, address, city, state, zip_code } = values as ClientUpdateForm
    emit('submit', { name, document_type_guid, tax_id, address, city, state, zip_code, contacts })
  }
})
</script>

<template>
  <a-form class="form" layout="vertical" @submit.prevent="onSubmit">
    <FormSection title="Datos del cliente">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :sm="12">
          <a-form-item
            label="Nombre / Razón social"
            :validate-status="errors.name ? 'error' : ''"
            :help="errors.name ?? ''"
          >
            <a-input
              v-model:value="name"
              v-bind="nameAttrs"
              placeholder="Ej: Establecimiento El Ombú S.A."
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="12">
          <a-form-item
            label="País"
            :validate-status="(errors as Record<string, string>).country_guid ? 'error' : ''"
            :help="(errors as Record<string, string>).country_guid ?? ''"
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
      </a-row>

      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="12">
          <a-form-item
            label="Tipo de documento"
            :validate-status="(errors as Record<string, string>).document_type_guid ? 'error' : ''"
            :help="(errors as Record<string, string>).document_type_guid ?? ''"
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
    </FormSection>

    <FormSection
      title="Contactos del cliente"
      subtitle="Emails y teléfonos de contacto. El contacto principal es el que se muestra por defecto."
    >
      <ContactsInput v-model="localContacts" />
    </FormSection>

    <FormSection title="Dirección" subtitle="Datos opcionales de ubicación.">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="12">
          <a-form-item
            label="Dirección"
            :validate-status="errors.address ? 'error' : ''"
            :help="errors.address ?? ''"
          >
            <a-input
              v-model:value="address"
              v-bind="addressAttrs"
              placeholder="Ej: Ruta 7 km 432"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="12">
          <a-form-item
            label="Ciudad"
            :validate-status="errors.city ? 'error' : ''"
            :help="errors.city ?? ''"
          >
            <a-input
              v-model:value="city"
              v-bind="cityAttrs"
              placeholder="Ej: General Villegas"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="12">
          <a-form-item
            label="Provincia / Estado"
            :validate-status="errors.state ? 'error' : ''"
            :help="errors.state ?? ''"
          >
            <a-input
              v-model:value="state"
              v-bind="stateAttrs"
              placeholder="Ej: Buenos Aires"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="12">
          <a-form-item
            label="Código postal"
            :validate-status="errors.zip_code ? 'error' : ''"
            :help="errors.zip_code ?? ''"
          >
            <a-input
              v-model:value="zip_code"
              v-bind="zipCodeAttrs"
              placeholder="Ej: 6230"
            />
          </a-form-item>
        </a-col>
      </a-row>
    </FormSection>

    <FormFooter
      :loading="loading"
      :save-label="mode === 'create' ? 'Crear cliente' : 'Guardar cambios'"
    />
  </a-form>
</template>

<style scoped>
.form { display: flex; flex-direction: column; gap: 20px; }
</style>
