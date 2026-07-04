<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import VetFilters from '../../components/VetFilters.vue'
import VetsTable from '../../components/VetsTable.vue'
import { useVets } from '../../composables/useVets'
import { useVetsUiStore } from '../../stores/vets-ui.store'
import { useColumnVisibility } from '@/core/composables/useColumnVisibility'
import type { VetFilters as VetFiltersType } from '../../types/vet.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'

const VET_TABLE_COLUMNS: TableColumnDef[] = [
  { key: 'name',       title: 'Nombre' },
  { key: 'country',    title: 'País' },
  { key: 'status',     title: 'Estado' },
  { key: 'created_at', title: 'Creada' },
  { key: 'actions',    title: 'Acciones', width: 120, alwaysVisible: true },
]

const { visibleKeys, visibleColumns, selectableColumns, isDrawerOpen } =
  useColumnVisibility('vets-table', VET_TABLE_COLUMNS)

const router = useRouter()
const uiStore = useVetsUiStore()

const filtersModel = computed<VetFiltersType>({
  get: () => uiStore.filters,
  set: (val) => Object.assign(uiStore.filters, val),
})

const { data, isLoading } = useVets(
  computed(() => ({ ...uiStore.filters, search: uiStore.debouncedSearch })),
)
</script>

<template>
  <div>
    <AppHeader title="Veterinarias" subtitle="Administración de veterinarias registradas en el sistema.">
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="vets.create">
          <BaseButton :size="buttonSize" @click="router.push('/admin/vets/new')">
            <template #icon><PlusOutlined /></template>
            Nueva veterinaria
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <VetFilters v-model:filters="filtersModel" />

    <div class="page-toolbar">
      <ColumnSelectorButton @click="isDrawerOpen = true" />
    </div>

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="No se encontraron veterinarias."
      icon="🏥"
    >
      <PermissionGuard permission="vets.create">
        <BaseButton variant="primary" class="mt-3" @click="router.push('/admin/vets/new')">
          <template #icon><PlusOutlined /></template>
          Crear primera veterinaria
        </BaseButton>
      </PermissionGuard>
    </EmptyState>

    <VetsTable
      v-else
      :vets="data?.data ?? []"
      :loading="isLoading"
      :columns="visibleColumns"
    />

    <BasePagination
      :page="uiStore.filters.page"
      :total="data?.total ?? 0"
      :per-page="uiStore.filters.per_page"
      @change="({ page, perPage }) => { uiStore.filters.page = page; uiStore.filters.per_page = perPage }"
    />

    <ColumnSelectorDrawer
      v-model="isDrawerOpen"
      v-model:keys="visibleKeys"
      :columns="selectableColumns"
    />
  </div>
</template>

<style scoped>
.page-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 8px;
}

.mt-3 { margin-top: 12px; }

.vl-btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 18px;
  border-radius: 10px;
  border: none;
  background: var(--dt-accent, #1AE5A0);
  color: #060F1C;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s;
}
.vl-btn-primary:hover { opacity: 0.85; }
</style>
