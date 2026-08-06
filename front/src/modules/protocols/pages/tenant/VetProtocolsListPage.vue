<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import VetProtocolsTable from '../../components/tenant/VetProtocolsTable.vue'
import VetProtocolFormDrawer from '../../components/tenant/VetProtocolFormDrawer.vue'
import VetProtocolDeleteModal from '../../components/tenant/VetProtocolDeleteModal.vue'
import { useVetProtocolList } from '../../composables/useVetProtocolList'
import { useVetProtocolDetail } from '../../composables/useVetProtocolDetail'
import { useTechniqueTree } from '../../composables/useTechniqueTree'
import {
  useCreateVetProtocol,
  useUpdateVetProtocol,
  useDeleteVetProtocolWithModal,
} from '../../composables/useVetProtocolMutations'
import type { VetProtocolListItem, VetProtocolListParams, CreateVetProtocolPayload } from '../../types/vet-protocol.types'
import type { ProtocolFormValues } from '../../validators/protocol.validator'

const route = useRoute()
const router = useRouter()

const filters = reactive<{ technique_id?: string; search?: string; page: number; per_page: number }>({
  page: 1,
  per_page: 15,
})

const listParams = computed<VetProtocolListParams>(() => ({
  technique_id: filters.technique_id || undefined,
  search: filters.search || undefined,
  page: filters.page,
  per_page: filters.per_page,
}))

const { data, isLoading } = useVetProtocolList(listParams)

const { data: techniques } = useTechniqueTree()

const techniqueFilterOptions = computed(() =>
  (techniques.value ?? []).flatMap((root) =>
    root.children
      .filter((child) => Boolean(child.guid))
      .map((child) => ({ value: child.guid as string, label: `${root.name} / ${child.name}` })),
  ),
)

function onTechniqueFilterChange(value: string | number | null) {
  filters.technique_id = (value as string) || undefined
  filters.page = 1
}

// --- Drawer alta/edición ---

const showFormDrawer = ref(false)
const editingGuid = ref<string | null>(null)
const versionSourceGuid = ref<string | null>(null)

const { data: editingProtocol } = useVetProtocolDetail(editingGuid)
const { data: versionSourceProtocol } = useVetProtocolDetail(versionSourceGuid)

const { mutate: mutateCreate, isPending: isCreating, fieldErrors: createErrors } = useCreateVetProtocol()
const { mutate: mutateUpdate, isPending: isUpdating, fieldErrors: updateErrors } = useUpdateVetProtocol()

const isSavingForm = computed(() => isCreating.value || isUpdating.value)
const formFieldErrors = computed(() => (editingGuid.value ? updateErrors.value : createErrors.value))

function goToDetail(protocol: VetProtocolListItem) {
  router.push({ name: 'vet-protocols-detail', params: { vetGuid: route.params.vetGuid, guid: protocol.guid } })
}

function openCreateDrawer() {
  editingGuid.value = null
  versionSourceGuid.value = null
  showFormDrawer.value = true
}

function openEditDrawer(protocol: VetProtocolListItem) {
  editingGuid.value = protocol.guid
  versionSourceGuid.value = null
  showFormDrawer.value = true
}

function openCreateVersionDrawer(protocol: VetProtocolListItem) {
  versionSourceGuid.value = protocol.guid
  editingGuid.value = null
  showFormDrawer.value = true
}

function closeFormDrawer() {
  showFormDrawer.value = false
  editingGuid.value = null
  versionSourceGuid.value = null
}

function toPayload(values: ProtocolFormValues): CreateVetProtocolPayload {
  return {
    technique_id: values.technique_id,
    name: values.name,
    color: values.color,
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
    mutateUpdate({ guid: editingGuid.value, payload }, { onSuccess: () => closeFormDrawer() })
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
} = useDeleteVetProtocolWithModal()
</script>

<template>
  <div>
    <AppHeader title="Protocolos" subtitle="Protocolos globales y propios de tu veterinaria.">
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="protocols.create">
          <BaseButton :size="buttonSize" @click="openCreateDrawer">
            <template #icon><PlusOutlined /></template>
            Nuevo protocolo
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <div class="vplp-toolbar">
      <BaseSelect
        v-model="filters.technique_id"
        :options="techniqueFilterOptions"
        placeholder="Filtrar por sub-técnica"
        style="width: 260px"
        @update:model-value="onTechniqueFilterChange"
      />
      <a-input
        v-model:value="filters.search"
        allow-clear
        placeholder="Buscar por nombre"
        style="width: 220px"
        @update:value="filters.page = 1"
      />
    </div>

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="No hay protocolos disponibles todavía."
      icon="📋"
    >
      <PermissionGuard permission="protocols.create">
        <BaseButton variant="primary" class="mt-3" @click="openCreateDrawer">
          <template #icon><PlusOutlined /></template>
          Crear primer protocolo
        </BaseButton>
      </PermissionGuard>
    </EmptyState>

    <VetProtocolsTable
      v-else
      :protocols="data?.data ?? []"
      :loading="isLoading"
      @view="goToDetail"
      @edit="openEditDrawer"
      @delete="openDeleteModal"
      @create-version="openCreateVersionDrawer"
    />

    <BasePagination
      :page="filters.page"
      :total="data?.total ?? 0"
      :per-page="filters.per_page"
      @change="({ page, perPage }: { page: number; perPage: number }) => { filters.page = page; filters.per_page = perPage }"
    />

    <VetProtocolFormDrawer
      v-if="showFormDrawer"
      v-model:open="showFormDrawer"
      :loading="isSavingForm"
      :field-errors="formFieldErrors"
      :initial-values="editingProtocol ?? null"
      :version-source="versionSourceProtocol ?? null"
      @submit="onFormSubmit"
      @cancel="closeFormDrawer"
    />

    <VetProtocolDeleteModal
      v-model="showDeleteModal"
      :protocol="selectedProtocol"
      :is-pending="isDeleting"
      :blocked-error="deleteBlockedError"
      @confirm="confirmDelete"
      @cancel="closeDeleteModal"
    />
  </div>
</template>

<style scoped>
.vplp-toolbar {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.mt-3 {
  margin-top: 12px;
}
</style>
