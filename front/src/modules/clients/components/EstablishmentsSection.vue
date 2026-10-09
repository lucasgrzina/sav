<script setup lang="ts">
import { shallowRef } from 'vue'
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import EstablishmentFormModal from './modals/EstablishmentFormModal.vue'
import AdminEstablishmentFormModal from './modals/AdminEstablishmentFormModal.vue'
import { useClientEstablishments } from '../composables/useClientEstablishments'
import { useDeleteEstablishment } from '../composables/useDeleteEstablishment'
import { useAdminClientEstablishments } from '../composables/admin/useAdminClientEstablishments'
import { useAdminDeleteEstablishment } from '../composables/admin/useAdminDeleteEstablishment'
import type { EstablishmentItem } from '../types/client.types'
import { formatDate } from '@/core/utils/date'
import { getRoleLabel } from '@/core/utils/roles'

const props = defineProps<{
  clientGuid: string
  // Country of the client: scopes the province list of the establishment form
  countryGuid?: string
  mode: 'tenant' | 'admin'
}>()

const { data: establishments, isLoading } = props.mode === 'admin'
  ? useAdminClientEstablishments(() => props.clientGuid)
  : useClientEstablishments(() => props.clientGuid)

const { deleteEstablishment, isPending: isDeleting } = props.mode === 'admin'
  ? useAdminDeleteEstablishment(props.clientGuid)
  : useDeleteEstablishment(props.clientGuid)

const isModalOpen          = shallowRef(false)
const editingEstablishment = shallowRef<Partial<EstablishmentItem> | undefined>(undefined)
const modalMode            = shallowRef<'create' | 'edit'>('create')

function openCreateModal(): void {
  editingEstablishment.value = undefined
  modalMode.value            = 'create'
  isModalOpen.value          = true
}

function openEditModal(establishment: EstablishmentItem): void {
  editingEstablishment.value = establishment
  modalMode.value            = 'edit'
  isModalOpen.value          = true
}

const columns = [
  { title: 'Nombre',       key: 'name' },
  { title: 'RENSPA',       key: 'renspa' },
  { title: 'Ciudad/Prov.', key: 'location' },
  { title: 'Coordenadas',  key: 'coordinates' },
  { title: 'Personal vinculado', key: 'staff' },
  { title: 'Alta',         key: 'created_at' },
  { title: 'Acciones',     key: 'actions', width: 100 },
]
</script>

<template>
  <div class="es-section">
    <div class="es-header">
      <h4 class="es-title">Establecimientos</h4>
      <PermissionGuard permission="establishments.create">
        <BaseButton @click="openCreateModal">
          <template #icon><PlusOutlined /></template>
          Nuevo establecimiento
        </BaseButton>
      </PermissionGuard>
    </div>

    <EmptyState
      v-if="!isLoading && !establishments?.length"
      message="Todavía no cargaste establecimientos para este cliente. Agregá el primero: las alertas y protocolos se configuran a nivel establecimiento."
      icon="🏡"
    />

    <BaseDataTable
      v-else
      :columns="columns"
      :data-source="establishments ?? []"
      :loading="isLoading"
      row-key="guid"
      :pagination="false"
      :scroll="{ x: 1000 }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span v-if="record.name" class="es-mono">{{ record.name }}</span>
          <span v-else class="es-muted">—</span>
        </template>

        <template v-else-if="column.key === 'renspa'">
          <span v-if="record.renspa" class="es-mono">{{ record.renspa }}</span>
          <span v-else class="es-muted">—</span>
        </template>

        <template v-else-if="column.key === 'location'">
          <span v-if="record.city || record.state">
            {{ [record.city, record.state].filter(Boolean).join(', ') }}
          </span>
          <span v-else class="es-muted">—</span>
        </template>

        <template v-else-if="column.key === 'coordinates'">
          <span
            v-if="record.latitude !== null && record.longitude !== null"
            class="es-mono"
            data-testid="establishment-coordinates"
          >
            {{ record.latitude.toFixed(6) }}, {{ record.longitude.toFixed(6) }}
          </span>
          <span v-else class="es-muted">Sin coordenadas</span>
        </template>

        <template v-else-if="column.key === 'staff'">
          <div v-if="record.staff?.length" class="es-chips">
            <a-tag v-for="member in record.staff" :key="member.guid">
              {{ member.user.name }} · {{ getRoleLabel(member.role.name) }}
            </a-tag>
          </div>
          <a-tag v-else-if="(record.staff_count ?? record.staff?.length ?? 0) === 0" color="warning">
            Sin personal vinculado
          </a-tag>
          <span v-else class="es-muted">—</span>
        </template>

        <template v-else-if="column.key === 'created_at'">
          {{ formatDate(record.created_at) }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="establishments.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="openEditModal(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="establishments.delete">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Eliminar"
                danger
                :loading="isDeleting"
                @click="deleteEstablishment(record)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <component
      :is="mode === 'admin' ? AdminEstablishmentFormModal : EstablishmentFormModal"
      v-model="isModalOpen"
      :client-guid="clientGuid"
      :country-guid="countryGuid"
      :mode="modalMode"
      :initial="editingEstablishment"
    />
  </div>
</template>

<style scoped>
.es-section { display: flex; flex-direction: column; gap: 16px; }

.es-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.es-title {
  font-family: 'Syne', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0;
}

.es-chips { display: flex; flex-wrap: wrap; gap: 4px; }
.es-mono  { font-family: monospace; font-size: 12px; }
.es-muted { color: var(--dt-muted, #6B8CAE); font-style: italic; }
</style>
