<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import AdminClientsTable from '../../components/admin/AdminClientsTable.vue'
import ClientFilters from '../../components/ClientFilters.vue'
import { useAdminClients } from '../../composables/admin/useAdminClients'
import { useClientsUiStore } from '../../stores/clients-ui.store'
import type { ClientFilters as ClientFiltersType } from '../../types/client.types'

const router  = useRouter()
const uiStore = useClientsUiStore()

const filtersModel = computed<ClientFiltersType>({
  get: () => uiStore.filters,
  set: (val) => Object.assign(uiStore.filters, val),
})

const { data, isLoading } = useAdminClients(
  computed(() => ({ ...uiStore.filters, search: uiStore.debouncedSearch })),
)
</script>

<template>
  <div>
    <AppHeader
      title="Clientes"
      subtitle="Administración global de clientes del sistema."
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="clients.create">
          <BaseButton :size="buttonSize" @click="router.push('/admin/clients/new')">
            <template #icon><PlusOutlined /></template>
            Nuevo cliente
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <ClientFilters v-model:filters="filtersModel" />

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="No se encontraron clientes en el sistema."
      icon="🐾"
    >
      <PermissionGuard permission="clients.create">
        <BaseButton variant="primary" class="mt-3" @click="router.push('/admin/clients/new')">
          <template #icon><PlusOutlined /></template>
          Crear primer cliente
        </BaseButton>
      </PermissionGuard>
    </EmptyState>

    <AdminClientsTable
      v-else
      :clients="data?.data ?? []"
      :loading="isLoading"
    />

    <BasePagination
      :page="uiStore.filters.page"
      :total="data?.total ?? 0"
      :per-page="uiStore.filters.per_page"
      @change="({ page, perPage }) => { uiStore.filters.page = page; uiStore.filters.per_page = perPage }"
    />
  </div>
</template>

<style scoped>
.mt-3 { margin-top: 12px; }

.aclp-btn-primary {
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
.aclp-btn-primary:hover { opacity: 0.85; }
</style>
