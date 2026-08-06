<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined, ClockCircleOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseDataTable from '@/components/tables/BaseDataTable.vue'
import BaseTableActions from '@/components/tables/BaseTableActions.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import ProtocolFormDrawer from '@/modules/protocols/components/ProtocolFormDrawer.vue'
import ProtocolDeleteModal from '@/modules/protocols/components/ProtocolDeleteModal.vue'
import ProtocolSimulateDrawer from '@/modules/protocols/components/ProtocolSimulateDrawer.vue'
import { useProtocolList } from '@/modules/protocols/composables/useProtocolList'
import { useProtocolDetail } from '@/modules/protocols/composables/useProtocolDetail'
import {
  useCreateProtocol,
  useUpdateProtocol,
  useDeleteProtocolWithModal,
  useReplicateProtocol,
} from '@/modules/protocols/composables/useProtocolMutations'
import type { Technique } from '../types/technique.types'
import type { ProtocolListItem, ProtocolListParams, CreateProtocolPayload } from '@/modules/protocols/types/protocol.types'
import type { ProtocolFormValues } from '@/modules/protocols/validators/protocol.validator'

const props = defineProps<{ technique: Technique }>()

const filters = reactive<{ technique_id?: string; search?: string; page: number; per_page: number }>({
  page: 1,
  per_page: 15,
})

const listParams = computed<ProtocolListParams>(() => ({
  root_guid: props.technique.guid,
  technique_id: filters.technique_id || undefined,
  search: filters.search || undefined,
  page: filters.page,
  per_page: filters.per_page,
}))

const { data, isLoading } = useProtocolList(listParams)

const subTechniqueOptions = computed(() =>
  props.technique.children.map((child) => ({ value: child.guid as string, label: child.name })),
)

// --- Drawer alta/edición ---

const showFormDrawer = ref(false)
const editingGuid = ref<string | null>(null)

const { data: editingProtocol } = useProtocolDetail(editingGuid)

const { mutate: mutateCreate, isPending: isCreating, fieldErrors: createErrors } = useCreateProtocol()
const { mutate: mutateUpdate, isPending: isUpdating, fieldErrors: updateErrors } = useUpdateProtocol()

const isSavingForm = computed(() => isCreating.value || isUpdating.value)
const formFieldErrors = computed(() => (editingGuid.value ? updateErrors.value : createErrors.value))

function openCreateDrawer() {
  editingGuid.value = null
  showFormDrawer.value = true
}

function openEditDrawer(protocol: ProtocolListItem) {
  editingGuid.value = protocol.guid
  showFormDrawer.value = true
}

function closeFormDrawer() {
  showFormDrawer.value = false
  editingGuid.value = null
}

function toPayload(values: ProtocolFormValues): CreateProtocolPayload {
  return {
    technique_id: values.technique_id,
    name: values.name,
    color: values.color,
    country_id: values.country_id,
    tasks: values.tasks.map((t) => ({
      guid: t.guid,
      description: t.description,
      days_offset: t.days_offset,
      time_of_day: t.time_of_day,
      time: t.time,
      important: t.important,
      alerts: t.alerts.map((a) => ({
        guid: a.guid,
        offset_days: a.offset_days,
        time_of_day: a.time_of_day,
        time: a.time,
        roles: a.roles,
        message: a.message,
        require_confirmation: a.require_confirmation,
      })),
    })),
  }
}

function onFormSubmit(values: ProtocolFormValues) {
  const payload = toPayload(values)

  if (editingGuid.value) {
    mutateUpdate(
      { guid: editingGuid.value, payload },
      { onSuccess: () => closeFormDrawer() },
    )
  } else {
    mutateCreate(payload, { onSuccess: () => closeFormDrawer() })
  }
}

// --- Eliminar ---

const {
  selectedProtocol,
  showDeleteModal,
  isPending: isDeleting,
  deleteBlockedError,
  openDeleteModal,
  closeDeleteModal,
  confirmDelete,
} = useDeleteProtocolWithModal()

// --- Replicar ---

const { mutate: mutateReplicate, isPending: isReplicating } = useReplicateProtocol()

function replicateProtocol(protocol: ProtocolListItem) {
  mutateReplicate(protocol.guid)
}

// --- Simular ---

const showSimulateDrawer = ref(false)
const simulatingProtocol = ref<ProtocolListItem | null>(null)

function openSimulateDrawer(protocol: ProtocolListItem) {
  simulatingProtocol.value = protocol
  showSimulateDrawer.value = true
}

const columns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Sub-técnica', key: 'technique', dataIndex: 'technique' },
  { title: 'País', key: 'country', dataIndex: 'country' },
  { title: 'Tareas', key: 'tasks_count', dataIndex: 'tasks_count' },
  { title: 'Creado', key: 'created_at', dataIndex: 'created_at' },
  { title: '', key: 'actions' },
]
</script>

<template>
  <div>
    <a-empty
      v-if="technique.children.length === 0"
      description="Primero creá una sub-técnica para poder cargar protocolos."
    />

    <template v-else>
      <div class="tpt-toolbar">
        <a-select
          v-model:value="filters.technique_id"
          allow-clear
          placeholder="Filtrar por sub-técnica"
          style="width: 240px"
          :options="subTechniqueOptions"
        />
        <a-input v-model:value="filters.search" allow-clear placeholder="Buscar por nombre" style="width: 220px" />

        <PermissionGuard permission="protocols.create">
          <BaseButton variant="primary" @click="openCreateDrawer">
            <template #icon><PlusOutlined /></template>
            Nuevo protocolo
          </BaseButton>
        </PermissionGuard>
      </div>

      <a-empty
        v-if="!isLoading && (data?.total ?? 0) === 0"
        description="No hay protocolos vinculados a esta técnica todavía."
      />

      <BaseDataTable
        v-else
        :columns="columns"
        :data-source="data?.data ?? []"
        :loading="isLoading"
        row-key="guid"
        :pagination="{
          current: filters.page,
          pageSize: filters.per_page,
          total: data?.total ?? 0,
          onChange: (page: number) => (filters.page = page),
        }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'technique'">
            {{ (record as ProtocolListItem).technique.name }}
          </template>
          <template v-else-if="column.key === 'country'">
            <span v-if="(record as ProtocolListItem).is_global">Global</span>
            <span v-else>{{ (record as ProtocolListItem).country?.name }}</span>
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ new Date((record as ProtocolListItem).created_at).toLocaleDateString('es-AR') }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <BaseTableActions>
              <PermissionGuard permission="protocols.update">
                <BaseButton
                  variant="row-action"
                  size="small"
                  tooltip="Editar protocolo"
                  @click="openEditDrawer(record as ProtocolListItem)"
                >
                  <template #icon><EditOutlined /></template>
                </BaseButton>
              </PermissionGuard>
              <PermissionGuard permission="protocols.update">
                <BaseButton
                  variant="row-action"
                  size="small"
                  tooltip="Simular fecha"
                  @click="openSimulateDrawer(record as ProtocolListItem)"
                >
                  <template #icon><ClockCircleOutlined /></template>
                </BaseButton>
              </PermissionGuard>
              <PermissionGuard permission="protocols.create">
                <BaseButton
                  variant="row-action"
                  size="small"
                  tooltip="Replicar protocolo"
                  :loading="isReplicating"
                  @click="replicateProtocol(record as ProtocolListItem)"
                >
                  <template #icon><CopyOutlined /></template>
                </BaseButton>
              </PermissionGuard>
              <PermissionGuard permission="protocols.delete">
                <BaseButton
                  variant="row-action"
                  size="small"
                  danger
                  tooltip="Eliminar protocolo"
                  @click="openDeleteModal(record as ProtocolListItem)"
                >
                  <template #icon><DeleteOutlined /></template>
                </BaseButton>
              </PermissionGuard>
            </BaseTableActions>
          </template>
        </template>
      </BaseDataTable>

      <ProtocolFormDrawer
        v-if="showFormDrawer"
        v-model:open="showFormDrawer"
        :technique="technique"
        :loading="isSavingForm"
        :field-errors="formFieldErrors"
        :initial-values="editingProtocol ?? null"
        @submit="onFormSubmit"
        @cancel="closeFormDrawer"
      />

      <ProtocolDeleteModal
        v-model="showDeleteModal"
        :protocol="selectedProtocol"
        :is-pending="isDeleting"
        :blocked-error="deleteBlockedError"
        @confirm="confirmDelete"
        @cancel="closeDeleteModal"
      />

      <ProtocolSimulateDrawer
        v-if="showSimulateDrawer && simulatingProtocol"
        v-model:open="showSimulateDrawer"
        :protocol-guid="simulatingProtocol.guid"
        :protocol-name="simulatingProtocol.name"
      />
    </template>
  </div>
</template>

<style scoped>
.tpt-toolbar {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
</style>
