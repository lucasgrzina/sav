<script setup lang="ts">
import { computed } from 'vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import ProgramManagerCheckboxGroup from '../ProgramManagerCheckboxGroup.vue'
import type { ProgramManagerOption } from '@/modules/programs/types/program.types'

const props = withDefaults(
  defineProps<{
    clientId: string
    establishmentId: string
    vetStaffOptions: ProgramManagerOption[]
    clientStaffOptions: ProgramManagerOption[]
    loadingVetStaff?: boolean
    loadingClientStaff?: boolean
    allowedRoles?: string[] | null
    error?: string
  }>(),
  { loadingVetStaff: false, loadingClientStaff: false, allowedRoles: null, error: undefined },
)

const managerProfileIds = defineModel<string[]>('managerProfileIds', { required: true })

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

// Client managers are the staff linked to the chosen establishment.
const clientEmptyText = computed(() => {
  if (!props.clientId) return 'Seleccioná un cliente primero.'
  if (!props.establishmentId) return 'Elegí un establecimiento para ver su personal.'
  return 'Este establecimiento no tiene personal del cliente vinculado.'
})

function updateVetManagers(values: string[]) {
  managerProfileIds.value = [...values, ...selectedClientManagers.value]
}

function updateClientManagers(values: string[]) {
  managerProfileIds.value = [...selectedVetManagers.value, ...values]
}
</script>

<template>
  <BaseCard title="Responsables">
    <a-form-item
      :validate-status="error ? 'error' : ''"
      :help="error ?? ''"
      required
    >
      <div class="pms-managers">
        <div class="pms-managers-group">
          <span class="pms-managers-label">Empresa</span>
          <ProgramManagerCheckboxGroup
            :options="vetStaffOptions"
            :model-value="selectedVetManagers"
            :loading="loadingVetStaff"
            :allowed-roles="allowedRoles"
            @update:model-value="updateVetManagers"
          />
        </div>
        <div class="pms-managers-group">
          <span class="pms-managers-label">Cliente</span>
          <ProgramManagerCheckboxGroup
            :options="clientStaffOptions"
            :model-value="selectedClientManagers"
            :loading="loadingClientStaff"
            :empty-text="clientEmptyText"
            :allowed-roles="allowedRoles"
            @update:model-value="updateClientManagers"
          />
        </div>
      </div>
    </a-form-item>
  </BaseCard>
</template>

<style scoped>
.pms-managers {
  display: flex;
  gap: 32px;
  flex-wrap: wrap;
}

.pms-managers-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 200px;
}

.pms-managers-label {
  font-weight: 600;
  font-size: 13px;
}
</style>
