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
    // Client managers already assigned to the program but not linked to its establishment.
    staleClientManagers?: ProgramManagerOption[]
    // The options of the establishment could not be loaded (edit mode).
    clientOptionsError?: boolean
    allowedRoles?: string[] | null
    error?: string
  }>(),
  {
    loadingVetStaff: false,
    loadingClientStaff: false,
    staleClientManagers: () => [],
    clientOptionsError: false,
    allowedRoles: null,
    error: undefined,
  },
)

const managerProfileIds = defineModel<string[]>('managerProfileIds', { required: true })

// DEC-12: manager_profile_ids es un único array del contrato de API — se resuelve
// a qué bloque (vet/cliente) pertenece cada guid comparando contra las opciones
// que ya cargó cada endpoint de staff.
const vetStaffGuids = computed(() => new Set(props.vetStaffOptions.map((o) => o.guid)))
const clientStaffGuids = computed(() => new Set(props.clientStaffOptions.map((o) => o.guid)))
const staleGuids = computed(() => new Set(props.staleClientManagers.map((o) => o.guid)))

const selectedVetManagers = computed(() =>
  managerProfileIds.value.filter((guid) => vetStaffGuids.value.has(guid)),
)
const selectedClientManagers = computed(() =>
  managerProfileIds.value.filter((guid) => clientStaffGuids.value.has(guid)),
)
const selectedStaleManagers = computed(() =>
  managerProfileIds.value.filter((guid) => staleGuids.value.has(guid)),
)

// Anything selected that is not in a visible block (e.g. options still loading) is preserved as is.
const otherSelected = computed(() =>
  managerProfileIds.value.filter(
    (guid) => !vetStaffGuids.value.has(guid) && !clientStaffGuids.value.has(guid) && !staleGuids.value.has(guid),
  ),
)

// Client managers are the staff linked to the chosen establishment.
const clientEmptyText = computed(() => {
  if (!props.clientId) return 'Seleccioná un cliente primero.'
  if (!props.establishmentId) return 'Elegí un establecimiento para ver su personal.'
  return 'Este establecimiento no tiene personal del cliente vinculado.'
})

function updateVetManagers(values: string[]) {
  managerProfileIds.value = [
    ...values,
    ...selectedClientManagers.value,
    ...selectedStaleManagers.value,
    ...otherSelected.value,
  ]
}

function updateClientManagers(values: string[]) {
  managerProfileIds.value = [
    ...selectedVetManagers.value,
    ...values,
    ...selectedStaleManagers.value,
    ...otherSelected.value,
  ]
}

function updateStaleManagers(values: string[]) {
  managerProfileIds.value = [
    ...selectedVetManagers.value,
    ...selectedClientManagers.value,
    ...values,
    ...otherSelected.value,
  ]
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

      <a-alert
        v-if="clientOptionsError"
        class="pms-alert"
        type="error"
        show-icon
        message="No se pudo cargar el personal del cliente vinculado al establecimiento."
        description="Los responsables actuales se conservan, pero no podés guardar hasta que se carguen. Recargá la página para reintentar."
      />

      <div v-if="staleClientManagers.length" class="pms-stale" data-test="stale-managers">
        <a-alert
          type="warning"
          show-icon
          message="Responsables del programa no vinculados al establecimiento"
          description="Estas personas ya estaban asignadas al programa pero no están vinculadas al establecimiento. Si las dejás seleccionadas no vas a poder guardar. Quitalas acá, o vinculalas al establecimiento desde la ficha del cliente."
        />
        <ProgramManagerCheckboxGroup
          :options="staleClientManagers"
          :model-value="selectedStaleManagers"
          :allowed-roles="allowedRoles"
          @update:model-value="updateStaleManagers"
        />
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

.pms-alert,
.pms-stale {
  margin-top: 12px;
}

.pms-stale {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pms-managers-label {
  font-weight: 600;
  font-size: 13px;
}
</style>
