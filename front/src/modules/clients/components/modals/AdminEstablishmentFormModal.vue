<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { establishmentSchema } from '../../validators/client.validator'
import { useAdminCreateEstablishment } from '../../composables/admin/useAdminCreateEstablishment'
import { useAdminUpdateEstablishment } from '../../composables/admin/useAdminUpdateEstablishment'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { EstablishmentItem } from '../../types/client.types'
import type { EstablishmentForm } from '../../validators/client.validator'

const props = defineProps<{
  clientGuid: string
  mode: 'create' | 'edit'
  initial?: Partial<EstablishmentItem>
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  success: []
}>()

const isOpen = defineModel<boolean>({ default: false })

const { errors, defineField, handleSubmit, setErrors, setValues, resetForm } = useForm<EstablishmentForm>({
  validationSchema: toTypedSchema(establishmentSchema),
})

const [name, nameAttrs]         = defineField('name')
const [renspa, renspaAttrs]     = defineField('renspa')
const [address, addressAttrs]   = defineField('address')
const [city, cityAttrs]         = defineField('city')
const [state, stateAttrs]       = defineField('state')
const [zip_code, zipCodeAttrs]  = defineField('zip_code')

const createMutation = useAdminCreateEstablishment()
const updateMutation = useAdminUpdateEstablishment()

const isPending   = props.mode === 'create' ? createMutation.isPending : updateMutation.isPending
const fieldErrors = props.mode === 'create' ? createMutation.fieldErrors : updateMutation.fieldErrors

watch(() => props.initial, (vals) => {
  if (props.mode === 'edit' && vals) {
    setValues({
      name:     vals.name ?? '',
      renspa:   vals.renspa ?? null,
      address:  vals.address ?? null,
      city:     vals.city ?? null,
      state:    vals.state ?? null,
      zip_code: vals.zip_code ?? null,
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
  } else if (props.initial?.guid) {
    await updateMutation.mutateAsync(
      { clientGuid: props.clientGuid, estGuid: props.initial.guid, payload: values },
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
    :title="mode === 'create' ? 'Nuevo establecimiento' : 'Editar establecimiento'"
    :width="600"
    @cancel="handleCancel"
  >
    <a-form class="form" layout="vertical" @submit.prevent="onSubmit">
      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="16">
          <a-form-item
            label="Nombre"
            :validate-status="errors.name ? 'error' : ''"
            :help="errors.name ?? ''"
          >
            <a-input
              v-model:value="name"
              v-bind="nameAttrs"
              placeholder="Ej: Campo La Esperanza"
            />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="8">
          <a-form-item
            label="RENSPA"
            :validate-status="errors.renspa ? 'error' : ''"
            :help="errors.renspa ?? ''"
          >
            <a-input
              v-model:value="renspa"
              v-bind="renspaAttrs"
              placeholder="Ej: 06.001.0.00001/00"
            />
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item
        label="Dirección"
        :validate-status="errors.address ? 'error' : ''"
        :help="errors.address ?? ''"
      >
        <a-input
          v-model:value="address"
          v-bind="addressAttrs"
          placeholder="Ej: Ruta 5 km 100"
        />
      </a-form-item>

      <a-row :gutter="[16, 0]">
        <a-col :xs="24" :md="8">
          <a-form-item
            label="Ciudad"
            :validate-status="errors.city ? 'error' : ''"
            :help="errors.city ?? ''"
          >
            <a-input v-model:value="city" v-bind="cityAttrs" placeholder="Ej: Trenque Lauquen" />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="8">
          <a-form-item
            label="Provincia"
            :validate-status="errors.state ? 'error' : ''"
            :help="errors.state ?? ''"
          >
            <a-input v-model:value="state" v-bind="stateAttrs" placeholder="Ej: Buenos Aires" />
          </a-form-item>
        </a-col>

        <a-col :xs="24" :md="8">
          <a-form-item
            label="Código postal"
            :validate-status="errors.zip_code ? 'error' : ''"
            :help="errors.zip_code ?? ''"
          >
            <a-input v-model:value="zip_code" v-bind="zipCodeAttrs" placeholder="Ej: 6400" />
          </a-form-item>
        </a-col>
      </a-row>
    </a-form>

    <template #footer>
      <BaseButton variant="secondary" @click="handleCancel">Cancelar</BaseButton>
      <BaseButton
        variant="primary"
        :loading="isPending.value"
        @click="onSubmit"
      >
        {{ mode === 'create' ? 'Crear establecimiento' : 'Guardar cambios' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
