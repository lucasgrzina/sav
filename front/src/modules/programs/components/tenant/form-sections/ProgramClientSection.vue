<script setup lang="ts">
import { computed } from 'vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import ProgramManagerCheckboxGroup from '../ProgramManagerCheckboxGroup.vue'
import type { SelectOption } from '@/core/types/ui.types'
import type { ProgramManagerOption } from '@/modules/programs/types/program.types'

const props = withDefaults(
  defineProps<{
    clientOptions: SelectOption[]
    establishmentOptions: SelectOption[]
    vetStaffOptions: ProgramManagerOption[]
    clientStaffOptions: ProgramManagerOption[]
    loadingClients?: boolean
    loadingEstablishments?: boolean
    loadingVetStaff?: boolean
    loadingClientStaff?: boolean
    errors: {
      client_id?: string
      establishment_id?: string
      manager_profile_ids?: string
    }
  }>(),
  { loadingClients: false, loadingEstablishments: false, loadingVetStaff: false, loadingClientStaff: false },
)

const clientId = defineModel<string>('clientId', { required: true })
const establishmentId = defineModel<string>('establishmentId', { required: true })
const managerProfileIds = defineModel<string[]>('managerProfileIds', { required: true })

// Mientras la query de opciones (cliente/establecimiento) sigue en curso, el select puede tener
// ya un guid seteado (form hidratado en modo edición) sin la opción correspondiente todavía
// cargada. En ese caso a-select cae a mostrar el guid crudo como texto. Enmascaramos el valor
// visible con '' durante la carga — el spinner de :loading cubre el estado — y lo
// restauramos apenas la query resuelve.
const clientSelectValue = computed<string>({
  get: () => (props.loadingClients ? '' : clientId.value),
  set: (value) => { clientId.value = value },
})
const establishmentSelectValue = computed<string>({
  get: () => (props.loadingEstablishments ? '' : establishmentId.value),
  set: (value) => { establishmentId.value = value },
})

// DEC-12: manager_profile_ids es un único array del contrato de API — se resuelve
// a qué bloque (vet/cliente) pertenece cada guid comparando contra las opciones
// que ya cargó cada endpoint de staff.
const vetStaffGuids = computed(() => new Set(props.vetStaffOptions.map((o) => o.guid)))
const clientStaffGuids = computed(() => new Set(props.clientStaffOptions.map((o) => o.guid)))

const selectedVetManagers = computed(() =>
  managerProfileIds.value.filter((guid) => vetStaffGuids.value.has(guid)),
)
const selectedClientManagers = computed(() =>
  managerProfileIds.value.filter((guid) => clientStaffGuids.value.has(guid)),
)

function updateVetManagers(values: string[]) {
  managerProfileIds.value = [...values, ...selectedClientManagers.value]
}

function updateClientManagers(values: string[]) {
  managerProfileIds.value = [...selectedVetManagers.value, ...values]
}
</script>

<template>
  <BaseCard title="Datos del cliente">
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item
          label="Cliente"
          :validate-status="errors.client_id ? 'error' : ''"
          :help="errors.client_id ?? ''"
          required
        >
          <BaseSelect
            v-model="clientSelectValue"
            :options="clientOptions"
            :loading="loadingClients"
            placeholder="Seleccioná un cliente"
          />
        </a-form-item>
      </a-col>

      <a-col :xs="24" :md="12">
        <a-form-item
          label="Establecimiento"
          :validate-status="errors.establishment_id ? 'error' : ''"
          :help="errors.establishment_id ?? ''"
          required
        >
          <BaseSelect
            v-model="establishmentSelectValue"
            :options="establishmentOptions"
            :disabled="!clientId"
            :loading="loadingEstablishments"
            placeholder="Seleccioná un establecimiento"
          />
        </a-form-item>
      </a-col>
    </a-row>

    <a-form-item
      label="Responsables"
      :validate-status="errors.manager_profile_ids ? 'error' : ''"
      :help="errors.manager_profile_ids ?? ''"
      required
    >
      <div class="pcs-managers">
        <div class="pcs-managers-group">
          <span class="pcs-managers-label">Veterinaria</span>
          <ProgramManagerCheckboxGroup
            :options="vetStaffOptions"
            :model-value="selectedVetManagers"
            :loading="loadingVetStaff"
            @update:model-value="updateVetManagers"
          />
        </div>
        <div class="pcs-managers-group">
          <span class="pcs-managers-label">Cliente</span>
          <ProgramManagerCheckboxGroup
            :options="clientStaffOptions"
            :model-value="selectedClientManagers"
            :loading="loadingClientStaff"
            :empty-text="clientId ? 'El cliente no tiene responsables disponibles.' : 'Seleccioná un cliente primero.'"
            @update:model-value="updateClientManagers"
          />
        </div>
      </div>
    </a-form-item>
  </BaseCard>
</template>

<style scoped>
.pcs-managers {
  display: flex;
  gap: 32px;
  flex-wrap: wrap;
}

.pcs-managers-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 200px;
}

.pcs-managers-label {
  font-weight: 600;
  font-size: 13px;
}
</style>
