<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import ProgramsTable from '../../components/tenant/ProgramsTable.vue'
import ProgramCancelModal from '../../components/tenant/ProgramCancelModal.vue'
import ProgramShareModal from '../../components/tenant/ProgramShareModal.vue'
import { useProgramList } from '../../composables/useProgramList'
import { useClients } from '@/modules/clients/composables/useClients'
import { useCancelProgramWithModal } from '../../composables/useProgramMutations'
import { useRequestProgramPdf } from '../../composables/useRequestProgramPdf'
import type { ProgramListItem, ProgramListParams } from '../../types/program.types'

// DEC-11: el alta/edición dejó de ser un Drawer — esta página solo lista y navega a
// VetProgramFormPage.vue / VetProgramDetailPage.vue por router.
const router = useRouter()

const filters = reactive<{ client_id?: string; cancelled?: boolean; search?: string; page: number; per_page: number }>({
  page: 1,
  per_page: 15,
})

const listParams = computed<ProgramListParams>(() => ({
  client_id: filters.client_id || undefined,
  cancelled: filters.cancelled,
  search: filters.search || undefined,
  page: filters.page,
  per_page: filters.per_page,
}))

const { data, isLoading } = useProgramList(listParams)

const { data: clientsResponse } = useClients()
const clientFilterOptions = computed(
  () => clientsResponse.value?.data.map((c) => ({ value: c.guid, label: c.name })) ?? [],
)

function onClientFilterChange(value: string | number | null) {
  filters.client_id = (value as string) || undefined
  filters.page = 1
}

function goToCreate() {
  router.push({ name: 'vet-programs-new' })
}

// --- Cancelar ---

const {
  selectedProgram,
  showCancelModal,
  isPending: isCancelling,
  openCancelModal,
  closeCancelModal,
  confirmCancel,
} = useCancelProgramWithModal()

// --- Descargar PDF ---

const { mutate: requestPdf } = useRequestProgramPdf()

function onDownloadPdf(program: ProgramListItem) {
  requestPdf(program.guid)
}

// --- Enviar por WhatsApp ---

const selectedProgramGuid = ref<string | null>(null)
const shareModalOpen = ref(false)

function onOpenShareModal(program: ProgramListItem) {
  selectedProgramGuid.value = program.guid
  shareModalOpen.value = true
}
</script>

<template>
  <div>
    <AppHeader title="Programas" subtitle="Programas de reproducción de tu veterinaria.">
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="programs.create">
          <BaseButton :size="buttonSize" @click="goToCreate">
            <template #icon><PlusOutlined /></template>
            Nuevo programa
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <div class="vplp-toolbar">
      <BaseSelect
        v-model="filters.client_id"
        :options="clientFilterOptions"
        placeholder="Filtrar por cliente"
        style="width: 260px"
        @update:model-value="onClientFilterChange"
      />
      <a-input
        v-model:value="filters.search"
        allow-clear
        placeholder="Buscar por comentarios"
        style="width: 220px"
        @update:value="filters.page = 1"
      />
    </div>

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="No hay programas disponibles todavía."
      icon="📋"
    >
      <PermissionGuard permission="programs.create">
        <BaseButton variant="primary" class="mt-3" @click="goToCreate">
          <template #icon><PlusOutlined /></template>
          Crear primer programa
        </BaseButton>
      </PermissionGuard>
    </EmptyState>

    <ProgramsTable
      v-else
      :programs="data?.data ?? []"
      :loading="isLoading"
      @cancel="openCancelModal"
      @download-pdf="onDownloadPdf"
      @open-share="onOpenShareModal"
    />

    <BasePagination
      :page="filters.page"
      :total="data?.total ?? 0"
      :per-page="filters.per_page"
      @change="({ page, perPage }: { page: number; perPage: number }) => { filters.page = page; filters.per_page = perPage }"
    />

    <ProgramCancelModal
      v-model="showCancelModal"
      :program="selectedProgram"
      :is-pending="isCancelling"
      @confirm="confirmCancel"
      @cancel="closeCancelModal"
    />

    <ProgramShareModal v-model:open="shareModalOpen" :program-guid="selectedProgramGuid" />
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
