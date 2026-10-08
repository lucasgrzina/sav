<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { establishmentSchema } from '../../validators/client.validator'
import { useAdminCreateEstablishment } from '../../composables/admin/useAdminCreateEstablishment'
import { useAdminUpdateEstablishment } from '../../composables/admin/useAdminUpdateEstablishment'
import { useAdminSyncEstablishmentStaff } from '../../composables/admin/useAdminSyncEstablishmentStaff'
import { useAdminClientStaff } from '../../composables/admin/useAdminClientStaff'
import EstablishmentStaffField from '../forms/EstablishmentStaffField.vue'
import { haveSameGuids } from '../../utils/establishment-staff'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { usePermission } from '@/core/composables/usePermissions'
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
const syncStaffMutation = useAdminSyncEstablishmentStaff()

const { can } = usePermission()
const canManageStaff = computed(() => can('establishments.update'))

const { data: clientStaff, isLoading: isLoadingStaff } = useAdminClientStaff(computed(() => props.clientGuid))

// Linked staff is not part of the establishment payload: it is synced in a second step.
const staffGuids   = ref<string[]>([])
const initialStaff = ref<string[]>([])

const isPending   = computed(() => createMutation.isPending.value || updateMutation.isPending.value || syncStaffMutation.isPending.value)
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
    initialStaff.value = (vals.staff ?? []).map((member) => member.guid)
    staffGuids.value   = [...initialStaff.value]
  } else if (props.mode === 'create') {
    // "New establishment" must not inherit the staff selection of a previous one
    initialStaff.value = []
    staffGuids.value   = []
  }
}, { immediate: true, deep: true })

watch(fieldErrors, (errs) => {
  setErrors(errs ?? {})
})

// Second step: only when the user can manage staff and the selection actually changed.
// Returns false when the sync failed (the establishment itself is already saved).
async function syncStaffIfNeeded(estGuid: string): Promise<boolean> {
  if (!canManageStaff.value) return true
  if (haveSameGuids(initialStaff.value, staffGuids.value)) return true
  try {
    await syncStaffMutation.mutateAsync({
      clientGuid: props.clientGuid,
      estGuid,
      payload: { user_profile_guids: staffGuids.value },
    })
    initialStaff.value = [...staffGuids.value]
    return true
  } catch {
    // The mutation composable already notified the error
    return false
  }
}

function closeAndReset(): void {
  resetForm()
  syncStaffMutation.resetErrors()
  staffGuids.value   = []
  initialStaff.value = []
  isOpen.value       = false
  emit('success')
}

const onSubmit = handleSubmit(async (values) => {
  if (props.mode === 'create') {
    let created: EstablishmentItem
    try {
      created = await createMutation.mutateAsync({ clientGuid: props.clientGuid, payload: values })
    } catch {
      return
    }
    // The establishment exists now: on sync failure close anyway, retry from edit mode
    // (re-submitting create would duplicate it).
    await syncStaffIfNeeded(created.guid)
    closeAndReset()
  } else if (props.initial?.guid) {
    try {
      await updateMutation.mutateAsync({ clientGuid: props.clientGuid, estGuid: props.initial.guid, payload: values })
    } catch {
      return
    }
    const synced = await syncStaffIfNeeded(props.initial.guid)
    if (!synced) return // keep the modal open so the user can retry the link
    initialStaff.value = [...staffGuids.value]
    isOpen.value = false
    emit('success')
  }
})

function handleCancel(): void {
  isOpen.value = false
  resetForm()
  syncStaffMutation.resetErrors()
  staffGuids.value = [...initialStaff.value]
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

      <PermissionGuard permission="establishments.update">
        <EstablishmentStaffField
          v-model="staffGuids"
          :staff-options="clientStaff ?? []"
          :initial-guids="initialStaff"
          :loading="isLoadingStaff"
          :error="syncStaffMutation.generalError.value ?? ''"
          @change="syncStaffMutation.resetErrors()"
        />
      </PermissionGuard>
    </a-form>

    <template #footer>
      <BaseButton variant="secondary" @click="handleCancel">Cancelar</BaseButton>
      <BaseButton
        variant="primary"
        :loading="isPending"
        @click="onSubmit"
      >
        {{ mode === 'create' ? 'Crear establecimiento' : 'Guardar cambios' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
