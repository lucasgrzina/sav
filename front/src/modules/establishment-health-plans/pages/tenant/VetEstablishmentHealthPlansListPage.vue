<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import EstablishmentHealthPlansTable from '../../components/tenant/EstablishmentHealthPlansTable.vue'
import EstablishmentHealthPlanCancelModal from '../../components/tenant/EstablishmentHealthPlanCancelModal.vue'
import { useEstablishmentHealthPlanList } from '../../composables/useEstablishmentHealthPlanList'
import { useCancelEstablishmentHealthPlanWithModal } from '../../composables/useEstablishmentHealthPlanMutations'
import { useEstablishmentHealthPlanUiStore } from '../../stores/establishment-health-plan-ui.store'
import { useClients } from '@/modules/clients/composables/useClients'
import { useClientEstablishments } from '@/modules/clients/composables/useClientEstablishments'
import type { EstablishmentHealthPlanListParams } from '../../types/establishment-health-plan.types'

const router = useRouter()
const uiStore = useEstablishmentHealthPlanUiStore()

const listParams = computed<EstablishmentHealthPlanListParams>(() => ({
  client_id: uiStore.filters.client_guid,
  establishment_id: uiStore.filters.establishment_guid,
  cancelled: uiStore.filters.cancelled,
  page: uiStore.filters.page,
  per_page: uiStore.filters.per_page,
}))

const { data, isLoading } = useEstablishmentHealthPlanList(listParams)

const { data: clientsResponse } = useClients()
const clientFilterOptions = computed(
  () => clientsResponse.value?.data.map((c) => ({ value: c.guid, label: c.name })) ?? [],
)

// El filtro por establecimiento depende del cliente elegido: la API que lista
// establecimientos de un cliente requiere su guid (mismo patrón que EstablishmentsSection).
const { data: establishmentsResponse, isLoading: isLoadingEstablishments } = useClientEstablishments(
  () => uiStore.filters.client_guid ?? '',
)
const establishmentFilterOptions = computed(
  () => establishmentsResponse.value?.map((e) => ({ value: e.guid, label: e.name })) ?? [],
)

function onClientFilterChange(value: string | number | null) {
  uiStore.filters.client_guid = (value as string) || undefined
  uiStore.filters.establishment_guid = undefined
  uiStore.filters.page = 1
}

function onEstablishmentFilterChange(value: string | number | null) {
  uiStore.filters.establishment_guid = (value as string) || undefined
  uiStore.filters.page = 1
}

function goToCreate() {
  router.push({ name: 'vet-health-plans-new' })
}

const {
  selectedPlan,
  showCancelModal,
  isPending: isCancelling,
  openCancelModal,
  closeCancelModal,
  confirmCancel,
} = useCancelEstablishmentHealthPlanWithModal()
</script>

<template>
  <div>
    <AppHeader title="Planes Sanitarios" subtitle="Planes sanitarios instanciados por establecimiento.">
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="establishment-health-plans.create">
          <BaseButton :size="buttonSize" @click="goToCreate">
            <template #icon><PlusOutlined /></template>
            Nuevo plan
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <div class="vehplp-toolbar">
      <BaseSelect
        v-model="uiStore.filters.client_guid"
        :options="clientFilterOptions"
        placeholder="Filtrar por cliente"
        style="width: 260px"
        @update:model-value="onClientFilterChange"
      />
      <BaseSelect
        v-model="uiStore.filters.establishment_guid"
        :options="establishmentFilterOptions"
        :loading="isLoadingEstablishments"
        :disabled="!uiStore.filters.client_guid"
        placeholder="Filtrar por establecimiento"
        style="width: 260px"
        @update:model-value="onEstablishmentFilterChange"
      />
    </div>

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="No hay planes sanitarios instanciados todavía."
      icon="🗓️"
    >
      <PermissionGuard permission="establishment-health-plans.create">
        <BaseButton variant="primary" class="mt-3" @click="goToCreate">
          <template #icon><PlusOutlined /></template>
          Crear primer plan
        </BaseButton>
      </PermissionGuard>
    </EmptyState>

    <EstablishmentHealthPlansTable
      v-else
      :plans="data?.data ?? []"
      :loading="isLoading"
      @cancel="openCancelModal"
    />

    <BasePagination
      :page="uiStore.filters.page"
      :total="data?.total ?? 0"
      :per-page="uiStore.filters.per_page"
      @change="({ page, perPage }: { page: number; perPage: number }) => { uiStore.filters.page = page; uiStore.filters.per_page = perPage }"
    />

    <EstablishmentHealthPlanCancelModal
      v-model="showCancelModal"
      :plan="selectedPlan"
      :is-pending="isCancelling"
      @confirm="confirmCancel"
      @cancel="closeCancelModal"
    />
  </div>
</template>

<style scoped>
.vehplp-toolbar {
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
